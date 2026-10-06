<?php

namespace App\Features\Group\Services;

use App\Models\GroupDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class GroupDraftService
{
    public function __construct(private readonly OwnerPublicationEligibility $eligibility) {}

    public function owned(User $user, GroupDraft $draft): GroupDraft
    {
        $this->eligibility->assertCanPrepare($user);
        abort_unless($draft->owner_id === $user->id && ! $draft->trashed(), 404);

        return $draft;
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, string $id, array $data): GroupDraft
    {
        $this->eligibility->assertCanPrepare($user);

        // firstOrCreate recovers a unique-key race; a retry never overwrites a newer save.
        // Deleted UUIDs remain reserved: an old in-flight publication or retry
        // must never attach itself to a different draft with a recycled UUID.
        $draft = GroupDraft::unguarded(fn () => GroupDraft::withTrashed()->firstOrCreate(['id' => $id], [
            'owner_id' => $user->id, 'data' => $data, 'version' => 1, 'status' => 'draft',
        ]));
        $this->owned($user, $draft);
        if (! $draft->wasRecentlyCreated && ($draft->data != $data || $draft->status !== 'draft')) {
            throw new ConflictHttpException('Ce brouillon existe déjà. Rechargez-le pour reprendre sa dernière version.');
        }

        return $draft;
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, GroupDraft $draft, int $version, array $data): GroupDraft
    {
        $this->owned($user, $draft);

        return DB::transaction(function () use ($user, $draft, $version, $data) {
            $locked = GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->owned($user, $locked);
            $this->assertEditable($locked, $version);
            $locked->forceFill(['data' => $data, 'version' => $version + 1])->save();

            return $locked;
        });
    }

    public function delete(User $user, GroupDraft $draft, int $version): void
    {
        $this->owned($user, $draft);
        DB::transaction(function () use ($draft, $version) {
            $locked = GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked, $version);
            $locked->forceFill(['data' => [], 'version' => $version + 1])->save();
            $locked->delete();
        });
    }

    private function assertEditable(GroupDraft $draft, int $version): void
    {
        if ($draft->status !== 'draft' || $draft->version !== $version) {
            throw new ConflictHttpException('Ce brouillon a changé ou sa publication a commencé. Rechargez-le avant de continuer.');
        }
    }

    public function reopen(User $user, GroupDraft $draft, int $version): GroupDraft
    {
        $this->owned($user, $draft);

        return DB::transaction(function () use ($draft, $version) {
            $locked = GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'publishing' || $locked->version !== $version) {
                throw new ConflictHttpException('Cette tentative a changé ou le groupe est déjà publié. Rechargez la page.');
            }
            // Invalidates any in-flight attempt before allowing edits again.
            // Its unpriced Stripe product can never make the draft payable.
            $locked->forceFill(['status' => 'draft', 'version' => $version + 1])->save();

            return $locked;
        });
    }
}
