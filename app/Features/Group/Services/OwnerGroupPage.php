<?php

namespace App\Features\Group\Services;

use App\Models\GroupDraft;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BillingCurrencies;
use App\Support\Currency;

class OwnerGroupPage
{
    public function __construct(
        private readonly OwnerPublicationEligibility $eligibility,
        private readonly GroupDraftData $data,
    ) {}

    public function props(User $user, ?GroupDraft $draft = null): array
    {
        $this->eligibility->assertCanPrepare($user);

        return [
            'supportedCurrencies' => Currency::SUPPORTED,
            'enabledCurrencies' => BillingCurrencies::enabled(),
            'subscriptions' => Subscription::where('is_active', true)->with('category')->orderBy('name')->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'slug' => $s->slug,
                'max_members' => $s->max_members, 'monthly_price' => $s->monthly_price,
                'access_mode' => $s->access_mode,
                'category' => $s->category?->name ?? '', 'currency' => $s->currency, 'tier' => $s->tier,
            ]),
            'draft' => $draft ? $this->draft($draft) : null,
            'ownerReadiness' => $this->eligibility->state($user),
        ];
    }

    public function draft(GroupDraft $draft): array
    {
        return [
            'id' => $draft->id, 'version' => $draft->version, 'status' => $draft->status,
            'data' => GroupVisibility::normalizeData($draft->data), 'updated_at' => $draft->updated_at->toIso8601String(),
            'published_group_id' => $draft->published_group_id,
            'preview' => $this->data->preview($draft->data),
        ];
    }
}
