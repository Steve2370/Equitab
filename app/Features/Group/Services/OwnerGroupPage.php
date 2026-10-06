<?php

namespace App\Features\Group\Services;

use App\Models\GroupDraft;
use App\Models\Subscription;
use App\Models\User;

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
            'subscriptions' => Subscription::where('is_active', true)->with('category')->orderBy('name')->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'slug' => $s->slug,
                'max_members' => $s->max_members, 'monthly_price' => $s->monthly_price,
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
            'data' => $draft->data, 'updated_at' => $draft->updated_at->toIso8601String(),
            'published_group_id' => $draft->published_group_id,
            'preview' => $this->data->preview($draft->data),
        ];
    }
}
