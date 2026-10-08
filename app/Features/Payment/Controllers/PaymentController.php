<?php

namespace App\Features\Payment\Controllers;

use App\Features\Group\Services\GroupAccess;
use App\Features\Payment\Services\OwnerOnboardingAccess;
use App\Features\Payment\Services\OwnerOnboardingException;
use App\Features\Payment\Services\OwnerOnboardingService;
use App\Features\Payment\Services\PaymentConfirmationNotifier;
use App\Features\Payment\Services\PaymentService;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly GroupAccess $access,
    ) {}

    public function initiate(Group $group, Request $request): JsonResponse
    {
        return response()->json(['message' => 'Utilisez POST /groups/{group}/subscribe'], 410);
    }

    public function startOnboarding(Request $request, OwnerOnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate(['draft_id' => ['nullable', 'uuid']]);
        try {
            return response()->json(['url' => $onboarding->start($request->user(), $data['draft_id'] ?? null)]);
        } catch (OwnerOnboardingException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function returnFromOnboarding(Request $request, OwnerOnboardingService $onboarding, OwnerOnboardingAccess $access): RedirectResponse
    {
        $data = $request->validate(['draft_id' => ['nullable', 'uuid']]);
        $draftId = $data['draft_id'] ?? null;
        $access->authorize($request->user()->fresh(), $draftId);
        $destination = $access->destination($draftId);
        try {
            $onboarding->refresh($request->user());
        } catch (OwnerOnboardingException $e) {
            return redirect()->to($destination)->with('error', $e->getMessage());
        }

        return redirect()->to($destination);
    }

    public function refreshOnboarding(Request $request, OwnerOnboardingService $onboarding, OwnerOnboardingAccess $access): RedirectResponse
    {
        $data = $request->validate(['draft_id' => ['nullable', 'uuid']]);
        $draftId = $data['draft_id'] ?? null;
        $access->authorize($request->user()->fresh(), $draftId);
        try {
            return redirect()->away($onboarding->start($request->user(), $draftId));
        } catch (OwnerOnboardingException $e) {
            return redirect()->to($access->destination($draftId))->with('error', $e->getMessage());
        }
    }

    public function calculateProration(Group $group, Request $request): JsonResponse
    {
        $data = $request->validate(['invite_token' => ['nullable', 'string', 'max:255']]);
        abort_unless($this->access->canView($request->user(), $group)
            || $this->access->hasValidInvitation($group, $data['invite_token'] ?? null), 404);
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
            'currency' => Currency::normalize($group->currency),
            'days_remaining' => $daysRemaining,
            'next_billing_date' => now()->addMonthNoOverflow()->startOfMonth()->format('d M Y'),
        ]);
    }

    public function startIdentityVerification(Request $request, OwnerOnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate(['draft_id' => ['nullable', 'uuid']]);
        $draftId = $data['draft_id'] ?? null;
        try {
            $result = $onboarding->startIdentity($request->user(), $draftId);
            if ($draftId !== null && $request->hasSession()) {
                $request->session()->put('owner_draft_id', $draftId);
            }

            return response()->json(['url' => $result['url']]);
        } catch (OwnerOnboardingException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function subscribe(Group $group, Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => ['required', 'string', 'max:255', 'regex:/^pm_[A-Za-z0-9_]+$/'],
            'invite_token' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->paymentService->initiateSubscription(
                payer: $request->user(),
                group: $group,
                paymentMethodId: $request->payment_method_id,
                inviteToken: $request->input('invite_token'),
            );

            return response()->json([...$result, 'currency' => Currency::normalize($group->currency)]);
        } catch (HttpExceptionInterface|ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            Log::warning('Souscription à rapprocher.', ['group_id' => $group->id, 'user_id' => $request->user()->id]);

            return response()->json(['message' => 'La confirmation est momentanément indisponible. Réessayez sans recommencer le paiement.'], 503);
        }
    }

    public function success(Request $request): Response
    {
        $groupId = $request->query('group_id');
        $group = Group::with(['subscription', 'owner'])->findOrFail($groupId);
        $user = $request->user();
        abort_unless($this->access->canView($user, $group), 404);

        $member = $group->members()->where('user_id', $user->id)->first();
        $isMemberActive = $this->access->canUseService($user, $group);

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
                'currency' => Currency::normalize($group->currency),
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

    public function dispute(Request $request, Payment $payment): JsonResponse
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

    public function confirmSubscription(Request $request, PaymentConfirmationNotifier $notifications): JsonResponse
    {
        $request->validate([
            'subscription_id' => ['required', 'string', 'max:255', 'regex:/^sub_[A-Za-z0-9_]+$/'],
        ]);

        $member = GroupMember::where('stripe_subscription_id', $request->subscription_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $member) {
            return response()->json(['message' => 'Abonnement introuvable.'], 404);
        }

        if (in_array($member->status, ['left', 'kicked'], true) || $member->cancellation_requested_at || ! $member->group) {
            return response()->json(['message' => 'Cette adhésion est terminée.'], 422);
        }
        try {
            $payment = $this->paymentService->synchronizeSubscription($request->subscription_id);
        } catch (Throwable) {
            Log::warning('Confirmation Stripe à reprendre.', ['member_id' => $member->id]);

            return response()->json(['message' => 'La confirmation est momentanément indisponible. Réessayez plus tard.'], 503);
        }
        $member->refresh();
        if (! $payment || $payment->status !== 'completed' || ! $this->access->canUseService($request->user()->fresh(), $member->group)) {
            return response()->json(['message' => 'Paiement non confirmé par Stripe.'], 422);
        }
        $notifications->sendOnce($payment, $member);

        return response()->json(['message' => 'Abonnement activé.']);
    }
}
