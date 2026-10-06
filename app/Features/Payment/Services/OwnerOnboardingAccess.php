<?php

namespace App\Features\Payment\Services;

use App\Features\Group\Services\OwnerPublicationEligibility;
use App\Models\GroupDraft;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OwnerOnboardingAccess
{
    public function __construct(private readonly OwnerPublicationEligibility $eligibility) {}

    public function authorize(User $user, ?string $draftId = null): void
    {
        $this->eligibility->assertCanPrepare($user);
        if ($user->status === 'banned') {
            throw new AuthorizationException('Ce compte ne peut pas démarrer la vérification.');
        }

        if ($draftId !== null && (! Str::isUuid($draftId) || ! GroupDraft::query()
            ->whereKey($draftId)->where('owner_id', $user->id)->exists())) {
            throw ValidationException::withMessages(['draft_id' => 'Ce brouillon est indisponible.']);
        }
    }

    public function destination(?string $draftId): string
    {
        return $draftId === null
            ? url('/dashboard/profile')
            : route('group-drafts.edit', ['draft' => $draftId]);
    }
}
