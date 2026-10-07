<?php

namespace Tests\Postgres;

use App\Features\Group\Services\GroupService;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class VisibilityConcurrencyTest extends PostgresTestCase
{
    public function test_two_visibility_changes_cannot_create_competing_links_and_retry_preserves_the_winner(): void
    {
        $owner = $this->readyOwner();
        $group = Group::factory()->for($owner, 'owner')->create();
        $process = new Process([PHP_BINARY, base_path('tests/Support/visibility-concurrency-worker.php'),
            (string) $owner->id, (string) $group->id], base_path());
        $process->setTimeout(20);
        DB::beginTransaction();
        Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
        try {
            $process->start();
            $this->await(fn () => DB::table('cache_locks')->where('key', 'like', '%billing:group:'.$group->id)->exists(), 'Visibility worker did not acquire its group lock.');
            $this->await(fn () => (bool) DB::selectOne("SELECT 1 FROM pg_stat_activity WHERE application_name = 'visibility-qa-worker' AND wait_event_type = 'Lock'"), 'Visibility worker did not wait on the real PostgreSQL row lock.');
            // The competitor must use its own connection, just like a second HTTP request.
            // Reusing this coordinator's open transaction would poison it on a lock conflict.
            $competitor = new Process([PHP_BINARY, base_path('tests/Support/visibility-concurrency-worker.php'),
                (string) $owner->id, (string) $group->id], base_path());
            $competitor->setTimeout(8);
            $competitor->start();
            $this->assertSame(['status' => 503], $this->workerResult($competitor));
            $this->assertNull($group->fresh()->invite_token);
            $this->assertSame('public', $group->fresh()->visibility);
            DB::commit();
            $result = $this->workerResult($process);
            $this->assertSame('private', $result['visibility']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['token']);
            $retry = app(GroupService::class)->update($owner, $group, ['visibility' => 'private']);
            $this->assertSame($result['token'], $retry->invite_token);
            $this->assertDatabaseCount('groups', 1);
            $this->assertDatabaseCount('group_members', 0);
            $this->assertDatabaseCount('payments', 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
