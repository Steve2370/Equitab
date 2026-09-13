<?php

namespace App\Features\Group\Services;

use App\Models\Group;
use App\Models\User;
use App\Models\StripePrice;
use App\Features\Group\Repositories\Contracts\GroupRepositoryInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class GroupService
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function create(User $owner, array $data): Group
    {
        // L'écran de création bloque déjà la soumission si l'identité ou
        // Stripe Connect ne sont pas actifs, mais cette règle n'était
        // vérifiée que côté interface : un appel direct à POST /groups (web
        // ou API) la contournait complètement. GroupPolicy::create() portait
        // déjà exactement cette règle mais n'était jamais invoquée — on
        // l'applique ici, au seul endroit par lequel un groupe peut être créé.
        if ($owner->identity_status !== 'verified' || $owner->stripe_connect_status !== 'active') {
            throw new Exception('Votre identité et votre compte Stripe Connect doivent être vérifiés avant de créer un groupe.');
        }

        return DB::transaction(function () use ($owner, $data) {
            $group = $this->groupRepository->create([
                ...$data,
                'owner_id' => $owner->id,
                'current_members' => 1,
                'status' => 'open',
                'uuid' => Str::uuid(),
            ]);

            $group->load('subscription');

            $stripeData = $this->gateway->createProduct($group);

            StripePrice::create([
                'group_id' => $group->id,
                'stripe_price_id' => null,
                'stripe_product_id' => $stripeData['product_id'],
                'unit_amount' => $group->total_price,
                'currency' => $group->subscription->currency,
            ]);

            $group->members()->create([
                'user_id' => $owner->id,
                'role' => 'owner',
                'status' => 'active',
                'share_amount' => $group->calculateCurrentPricePerMember(),
                'joined_at' => now(),
            ]);

            if (in_array($data['visibility'] ?? 'public', ['invite_only', 'private'])) {
                $token = bin2hex(random_bytes(16));
                \Illuminate\Support\Facades\Log::info('Generating invite token', ['visibility' => $data['visibility'], 'token' => $token]);
                $group->update(['invite_token' => $token]);
            }

            return $group;
        });
    }

    /**
     * Rejoindre un groupe sans paiement immédiat (statut pending_payment).
     * Auparavant, seuls isFull() et "déjà membre" étaient vérifiés : ni la
     * visibilité du groupe, ni le token d'invitation, ni le statut réel du
     * groupe (fermé), ni le statut du compte (suspendu, email non vérifié)
     * n'étaient contrôlés côté serveur — un appel API direct pouvait donc
     * rejoindre un groupe privé/sur invitation sans lien d'invitation, ou
     * un compte suspendu pouvait continuer à rejoindre des groupes.
     */
    public function join(User $user, Group $group, ?string $inviteToken = null): void
    {
        DB::transaction(function () use ($user, $group, $inviteToken) {
            if ($group->status !== 'open') {
                throw new Exception('Ce groupe n\'accepte plus de nouveaux membres.');
            }

            if ($group->isFull()) {
                throw new Exception('Ce groupe est complet.');
            }

            if (in_array($group->visibility, ['private', 'invite_only'], true)) {
                if (! $inviteToken || ! hash_equals((string) $group->invite_token, $inviteToken)) {
                    throw new Exception('Ce groupe nécessite un lien d\'invitation valide.');
                }
            }

            if ($user->isSuspended()) {
                throw new Exception('Votre compte est actuellement suspendu.');
            }

            if ($user->email_verified_at === null) {
                throw new Exception('Votre adresse email doit être vérifiée avant de rejoindre un groupe.');
            }

            if ($group->owner_id === $user->id) {
                throw new Exception('Vous êtes le propriétaire de ce groupe.');
            }

            $alreadyMember = $group->members()
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyMember) {
                throw new Exception('Vous êtes déjà membre de ce groupe.');
            }

            $group->members()->create([
                'user_id' => $user->id,
                'role' => 'member',
                'status' => 'pending_payment',
                // price_per_member n'est jamais renseigné sur le groupe —
                // calculatePricePerMemberIfJoined() donne le partage réel
                // une fois ce membre ajouté (total_price / membres+1).
                'share_amount' => $group->calculatePricePerMemberIfJoined(),
                'joined_at' => now(),
                'next_payment_at' => now()->addDays(3),
            ]);

            $group->increment('current_members');

            if ($group->fresh()->isFull()) {
                $this->groupRepository->update($group, ['status' => 'full']);
            }
        });
    }

    public function leave(User $user, Group $group): void
    {
        DB::transaction(function () use ($user, $group) {
            $member = $group->members()
                ->where('user_id', $user->id)
                ->where('role', 'member')
                ->firstOrFail();

            // Un membre qui quitte gardait son abonnement Stripe actif —
            // il continuait donc à être facturé, sans plus faire partie du
            // groupe, jusqu'à ce que le renouvellement échoue de lui-même.
            if ($member->stripe_subscription_id) {
                try {
                    $this->gateway->cancelSubscription($member->stripe_subscription_id);
                } catch (Exception $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Annulation abonnement Stripe échouée pendant le départ du membre #{$member->id}: " . $e->getMessage()
                    );
                }
            }

            $wasActive = $member->status === 'active';

            $member->update([
                'status' => 'left',
                'subscription_status' => $member->stripe_subscription_id ? 'canceled' : $member->subscription_status,
            ]);

            if ($wasActive) {
                $group->decrement('current_members');
            }

            if ($group->status === 'full') {
                $this->groupRepository->update($group, ['status' => 'open']);
            }
        });
    }
}
