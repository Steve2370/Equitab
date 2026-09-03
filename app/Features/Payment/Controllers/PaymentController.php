<?php

namespace App\Features\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\GroupMember;
use App\Features\Payment\Services\PaymentService;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentConfirmed;
use App\Mail\NewMemberJoined;
use Inertia\Response;
use Inertia\Inertia;
use Exception;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function initiate(Group $group, Request $request): JsonResponse
    {
        return response()->json(['message' => 'Utilisez POST /groups/{group}/subscribe'], 410);
    }

    public function startOnboarding(Request $request): JsonResponse
    {
        $url = $this->gateway->createOnboardingLink(
            $request->user(),
            returnUrl: route('dashboard'),
            refreshUrl: route('dashboard'),
        );
        return response()->json(['url' => $url]);
    }

    public function calculateProration(Group $group): JsonResponse
    {
        // $group->price_per_member n'est jamais renseigné (le prix réel
        // vient de total_price / membres actifs) — calculatePricePerMemberIfJoined()
        // donne le prix tel qu'il sera pour quelqu'un qui n'a pas encore
        // rejoint, ce qui est le bon prix à afficher ici.
        $pricePerMember = $group->calculatePricePerMemberIfJoined();

        $now = now();
        $daysInMonth = $now->daysInMonth;
        $daysRemaining = $daysInMonth - $now->day + 1;
        $prorata = (int) round($pricePerMember * ($daysRemaining / $daysInMonth));

        return response()->json([
            'amount_today' => $prorata,
            'amount_recurring' => $pricePerMember,
            'days_remaining' => $daysRemaining,
            'next_billing_date' => now()->addMonthNoOverflow()->startOfMonth()->format('d M Y'),
        ]);
    }

    public function startIdentityVerification(Request $request): JsonResponse
    {
        $result = $this->gateway->createIdentityVerificationSession($request->user());
        return response()->json(['url' => $result['url']]);
    }

    public function subscribe(Group $group, Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => ['required', 'string'],
        ]);

        try {
            $result = $this->paymentService->initiateSubscription(
                payer: $request->user(),
                group: $group,
                paymentMethodId: $request->payment_method_id,
            );

            Log::info('Subscribe result', $result);

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('Subscribe error: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function success(Request $request): Response
    {
        $groupId = $request->query('group_id');
        $group = Group::with(['subscription', 'owner'])->findOrFail($groupId);
        $user = $request->user();

        $member = $group->members()->where('user_id', $user->id)->first();
        $isMemberActive = $member?->status === 'active';

        return Inertia::render('PaymentSuccess', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'subscriptionName' => $group->subscription->name,
                'subscriptionSlug' => $group->subscription->slug,
                'ownerName' => $group->owner->name,
                // group->price_per_member n'est jamais renseigné — le prix
                // réellement payé par ce membre est son share_amount (figé
                // au moment de la souscription). On retombe sur le calcul
                // dynamique seulement si le membre n'existe pas encore.
                'pricePerMember' => $member?->share_amount ?? $group->calculatePricePerMemberIfJoined(),
                'renewalDate' => $group->renewal_date?->format('d M Y'),
                'memberStatus' => $member?->status ?? 'pending_payment',
            ],
            'credentials' => $isMemberActive ? [
                'email' => $group->credential_email,
                'password' => $group->credential_password,
                'notes' => $group->credential_notes,
            ] : null,
        ]);
    }

    public function dispute(Request $request, \App\Models\Payment $payment): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'in:no_access,invalid_credentials,service_down,other'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        if ($payment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $existing = Dispute::where('payment_id', $payment->id)
            ->where('status', 'open')
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'Une dispute est déjà en cours pour ce paiement.'], 422);
        }

        $dispute = Dispute::create([
            'payment_id' => $payment->id,
            'user_id' => $request->user()->id,
            'group_id' => $payment->group_id,
            'reason' => $request->reason,
            'description' => $request->description,
        ]);

        Log::warning("Nouvelle dispute #{$dispute->id} - paiement #{$payment->id} — raison: {$request->reason}");

        return response()->json([
            'message' => 'Votre demande a été enregistrée. Nous la traiterons sous 48h.',
            'dispute' => $dispute,
        ], 201);
    }

    public function confirmSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'subscription_id' => ['required', 'string'],
        ]);

        $member = GroupMember::where('stripe_subscription_id', $request->subscription_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $member) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
        $subscription = $stripe->subscriptions->retrieve($request->subscription_id, [
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        $invoice = $subscription->latest_invoice;
        $paymentIntentStatus = $invoice?->payment_intent?->status ?? null;
        $invoiceStatus = $invoice?->status ?? null;

        if ($subscription->status !== 'active' && $paymentIntentStatus !== 'succeeded' && $invoiceStatus !== 'paid') {
            return response()->json(['message' => 'Paiement non confirmé par Stripe.'], 422);
        }

        // Délègue au même point d'entrée que le flux de souscription
        // synchrone et le webhook Stripe — idempotent via
        // stripe_payment_intent_id, donc rejouable sans risque même si le
        // webhook ou l'activation synchrone est déjà passé par là.
        $payment = $this->paymentService->activateMemberAndRecordPayment(
            member: $member,
            amountPaid: $invoice?->amount_paid ?? 0,
            currency: strtoupper($subscription->currency),
            paymentIntentId: $invoice?->payment_intent?->id,
        );

        Mail::to($member->user->email)
            ->send(new PaymentConfirmed($payment, $member));

        Mail::to($member->group->owner->email)
            ->send(new NewMemberJoined($member->group->load('subscription', 'owner'), $member->user, $member->share_amount));

        return response()->json(['message' => 'Abonnement activé.']);
    }
}
