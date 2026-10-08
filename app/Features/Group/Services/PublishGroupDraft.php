<?php

namespace App\Features\Group\Services;

use App\Features\Group\Contracts\GroupProductGateway;
use App\Features\Group\Exceptions\PublicationUnavailable;
use App\Features\Payment\Services\OwnerOnboardingService;
use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\StripePrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PublishGroupDraft
{
    public function __construct(
        private readonly GroupDraftService $drafts,
        private readonly GroupDraftData $data,
        private readonly OwnerPublicationEligibility $eligibility,
        private readonly OwnerOnboardingService $onboarding,
        private readonly GroupProductGateway $products,
        private readonly GroupInvitationLinks $invitationLinks,
        private readonly ServiceAccessPublication $serviceAccess,
    ) {}

    /** @param array<string, string|null> $credentials */
    public function publish(User $owner, GroupDraft $draft, int $version, array $credentials): Group
    {
        $this->drafts->owned($owner, $draft);
        if ($draft->version !== $version) {
            throw new ConflictHttpException('Le brouillon a changé. Rechargez-le avant de publier.');
        }
        if ($draft->status === 'published') {
            return Group::findOrFail($draft->published_group_id);
        }

        if ($subscription = Subscription::find($draft->data['subscription_id'] ?? null)) {
            $this->serviceAccess->validate($subscription, $credentials);
        }

        $this->eligibility->assertCanPublish($owner->fresh());
        $prepared = DB::transaction(function () use ($owner, $draft, $version) {
            $locked = GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->drafts->owned($owner, $locked);
            if ($locked->version !== $version) {
                throw new ConflictHttpException('Le brouillon a changé. Rechargez-le avant de publier.');
            }
            if ($locked->status === 'published') {
                return $locked;
            }
            $validated = $this->data->forPublication($locked->data);
            // Readiness itself calls Stripe. Persist the currency first, while
            // leaving the draft editable until readiness has been confirmed.
            if (! array_key_exists('currency', $locked->data)) {
                $locked->forceFill(['data' => [...$locked->data, 'currency' => $validated['currency']]])->save();
            }

            return $locked;
        });
        if ($prepared->status === 'published') {
            return Group::findOrFail($prepared->published_group_id);
        }

        // Do not keep a database lock open while waiting for Stripe.
        try {
            $this->onboarding->refresh($owner);
        } catch (\RuntimeException $e) {
            throw new PublicationUnavailable;
        }
        $this->eligibility->assertCanPublish($owner->fresh());

        $snapshot = DB::transaction(function () use ($owner, $draft, $version) {
            $locked = GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->drafts->owned($owner, $locked);
            if ($locked->version !== $version) {
                throw new ConflictHttpException('Le brouillon a changé. Rechargez-le avant de publier.');
            }
            if ($locked->status === 'published') {
                return $locked;
            }
            $this->data->forPublication($locked->data);
            $locked->forceFill(['status' => 'publishing'])->save();

            return $locked;
        });

        if ($snapshot->status === 'published') {
            return Group::findOrFail($snapshot->published_group_id);
        }

        $productId = $this->products->ensureProduct($snapshot->id, $snapshot->data['name'], $owner->id, $snapshot->version);

        return DB::transaction(function () use ($owner, $snapshot, $productId, $credentials) {
            $locked = GroupDraft::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $snapshot->version) {
                throw new ConflictHttpException('Cette tentative de publication a été remplacée. Rechargez le brouillon.');
            }
            if ($locked->status === 'published') {
                return Group::findOrFail($locked->published_group_id);
            }
            if ($locked->status !== 'publishing' || $locked->version !== $snapshot->version) {
                throw new ConflictHttpException('Le brouillon a changé. Sa publication a été interrompue.');
            }

            // A suspension or a webhook restriction during the remote call wins.
            $currentOwner = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $this->eligibility->assertCanPublish($currentOwner);
            $data = $this->data->forPublication($locked->data);
            $this->serviceAccess->validate(Subscription::findOrFail($data['subscription_id']), $credentials);
            $group = Group::create([
                ...$data,
                ...$credentials,
                'uuid' => $locked->id,
                'owner_id' => $owner->id,
                'current_members' => 1,
                'status' => 'open',
                ...$this->invitationLinks->attributes($data['visibility']),
            ]);
            StripePrice::create([
                'group_id' => $group->id,
                'stripe_product_id' => $productId,
                'stripe_price_id' => null,
                'unit_amount' => $group->total_price,
                'currency' => $group->currency,
            ]);
            $group->members()->create([
                'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active',
                'share_amount' => $group->total_price, 'joined_at' => now(),
            ]);
            $locked->forceFill(['status' => 'published', 'published_group_id' => $group->id])->save();

            return $group;
        });
    }
}
