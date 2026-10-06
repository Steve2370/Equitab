<?php

namespace Tests\Postgres;

use App\Features\Group\Services\GroupDraftService;
use App\Models\Group;
use App\Models\GroupDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OwnerConcurrencyTest extends PostgresTestCase
{
    public function test_two_creations_of_the_same_uuid_create_exactly_one_private_draft(): void
    {
        $owner = $this->readyOwner();
        $draft = new GroupDraft;
        $draft->id = (string) Str::uuid();
        $data = $this->validData();
        $a = $this->worker('create-a', 'create', $owner, $draft, ['data' => $data]);
        $b = $this->worker('create-b', 'create', $owner, $draft, ['data' => $data]);
        $this->assertSame(['status' => 200, 'draft_id' => $draft->id], $this->workerResult($a));
        $this->assertSame(['status' => 200, 'draft_id' => $draft->id], $this->workerResult($b));
        $this->assertSame(1, GroupDraft::count());
        $this->assertSame($data, GroupDraft::sole()->data);
        $this->assertNoPublication();
        $this->assertDatabaseCount('qa_products', 0);
        $this->assertDatabaseCount('qa_accounts', 0);
    }

    public function test_real_row_locks_serialize_two_saves_and_the_loser_gets_a_conflict(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        DB::beginTransaction();
        GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
        $a = $this->worker('save-a', 'save', $owner, $draft, ['data' => ['name' => 'A']]);
        $b = $this->worker('save-b', 'save', $owner, $draft, ['data' => ['name' => 'B']]);
        $this->await(function (): bool {
            DB::select('SELECT pg_stat_clear_snapshot()');

            return (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity
                WHERE application_name IN ('equitab-qa-save-a','equitab-qa-save-b') AND wait_event_type = 'Lock'")->count === 2;
        }, 'Both independent connections must wait on the real PostgreSQL row lock.');
        DB::commit();
        $results = [$this->workerResult($a), $this->workerResult($b)];
        $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'status'));
        $this->assertSame(2, $draft->fresh()->version);
        $this->assertSame(['name' => $results[0]['status'] === 200 ? 'A' : 'B'], $draft->fresh()->data);
        $this->assertNoPublication();
    }

    public function test_two_publications_finalize_the_same_group_once_without_a_network_transaction(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $a = $this->worker('publish-a', 'publish', $owner, $draft, ['pause' => 'product']);
        $this->awaitSignal('product:publish-a');
        $b = $this->worker('publish-b', 'publish', $owner, $draft, ['pause' => 'product']);
        $this->awaitSignal('product:publish-b');
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertNoPublication();
        // These locks would time out if either remote call held an SQL lock.
        DB::transaction(function () use ($draft, $owner): void {
            GroupDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $owner->newQuery()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
        });
        $this->release('publish-a');
        $this->release('publish-b');
        $first = $this->workerResult($a);
        $second = $this->workerResult($b);
        $this->assertSame(200, $first['status']);
        $this->assertSame($first, $second);
        $this->assertPublishedOnce($draft);
        $this->assertDatabaseCount('qa_products', 1);
        $this->assertSame($first, $this->workerResult($this->worker('publish-replay', 'publish', $owner, $draft)));
    }

    public function test_save_during_publication_cannot_change_the_reserved_snapshot(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $publishing = $this->worker('reserved', 'publish', $owner, $draft, ['pause' => 'product']);
        $this->awaitSignal('product:reserved');
        $this->assertSame(409, $this->workerResult($this->worker('late-save', 'save', $owner, $draft, ['data' => ['name' => 'Late']]))['status']);
        $this->release('reserved');
        $this->assertSame(200, $this->workerResult($publishing)['status']);
        $this->assertSame($draft->data['name'], Group::sole()->name);
        $this->assertPublishedOnce($draft);
    }

    public function test_reopen_and_new_publication_invalidate_an_older_in_flight_publication(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $old = $this->worker('old', 'publish', $owner, $draft, ['pause' => 'product']);
        $this->awaitSignal('product:old');
        $service = app(GroupDraftService::class);
        $reopened = $service->reopen($owner, $draft->fresh(), 1);
        $current = $service->save($owner, $reopened, 2, [...$draft->data, 'name' => 'Version confirmée']);
        $this->assertSame(200, $this->workerResult($this->worker('new', 'publish', $owner, $current, ['version' => 3]))['status']);
        $this->release('old');
        $this->assertSame(409, $this->workerResult($old)['status']);
        $this->assertPublishedOnce($draft);
        $this->assertSame('Version confirmée', Group::sole()->name);
        $this->assertDatabaseHas('stripe_prices', ['stripe_product_id' => 'prod_pg_'.$draft->id.'_v3']);
    }

    public function test_delete_reserves_the_uuid_and_cancels_an_in_flight_publication(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $old = $this->worker('deleted', 'publish', $owner, $draft, ['pause' => 'product']);
        $this->awaitSignal('product:deleted');
        $service = app(GroupDraftService::class);
        $reopened = $service->reopen($owner, $draft->fresh(), 1);
        $service->delete($owner, $reopened, 2);
        try {
            $service->create($owner, $draft->id, $draft->data);
            $this->fail('A deleted UUID was recycled.');
        } catch (NotFoundHttpException) {
            $this->assertSoftDeleted('group_drafts', ['id' => $draft->id]);
        }
        $this->release('deleted');
        $this->assertSame(404, $this->workerResult($old)['status']);
        $this->assertSame([], GroupDraft::withTrashed()->findOrFail($draft->id)->data);
        $this->assertNoPublication();
    }

    public function test_restriction_from_another_process_wins_before_publication_commit(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $publication = $this->worker('restricted', 'publish', $owner, $draft, ['pause' => 'product']);
        $this->awaitSignal('product:restricted');
        DB::table('qa_remote_accounts')->insert(['id' => $owner->stripe_connect_account_id, 'active' => false]);
        $this->assertSame(200, $this->workerResult($this->worker('restriction-webhook', 'webhook', $owner, null, ['event' => 'evt_pg_restricted']))['status']);
        $this->release('restricted');
        $this->assertSame(422, $this->workerResult($publication)['status']);
        $this->assertSame('restricted', $owner->fresh()->stripe_connect_status);
        $this->assertNoPublication();
    }

    public function test_sql_failure_after_external_product_is_atomic_and_can_be_retried(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        DB::statement("ALTER TABLE group_members ADD CONSTRAINT qa_no_owner CHECK (role <> 'owner') NOT VALID");
        $failed = $this->workerResult($this->worker('sql-failure', 'publish', $owner, $draft));
        $this->assertSame(['status' => 500, 'sqlstate' => '23514'], $failed);
        $this->assertNoPublication();
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertDatabaseCount('qa_products', 1);
        DB::statement('ALTER TABLE group_members DROP CONSTRAINT qa_no_owner');
        $this->assertSame(200, $this->workerResult($this->worker('sql-retry', 'publish', $owner, $draft))['status']);
        $this->assertPublishedOnce($draft);
        $this->assertDatabaseCount('qa_products', 1);
    }

    public function test_connect_account_creation_is_serialized_across_processes(): void
    {
        $owner = $this->readyOwner(['stripe_connect_account_id' => null, 'stripe_connect_status' => 'not_started']);
        $a = $this->worker('connect-a', 'connect', $owner, null, ['pause' => 'account']);
        $this->awaitSignal('account:connect-a');
        $this->assertDatabaseCount('owner_connect_attempts', 1);
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertSame(503, $this->workerResult($this->worker('connect-b', 'connect', $owner))['status']);
        $this->release('connect-a');
        $this->assertSame(200, $this->workerResult($a)['status']);
        $this->assertSame(200, $this->workerResult($this->worker('connect-retry', 'connect', $owner))['status']);
        $this->assertSame(DB::table('qa_accounts')->sole()->id, $owner->fresh()->stripe_connect_account_id);
        $this->assertDatabaseCount('qa_accounts', 1);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
    }

    public function test_a_lost_account_response_reuses_the_durable_key_and_frozen_parameters(): void
    {
        $owner = $this->readyOwner(['stripe_connect_account_id' => null, 'stripe_connect_status' => 'not_started']);
        $this->assertSame(503, $this->workerResult($this->worker('lost', 'connect', $owner, null, ['lost_response' => true]))['status']);
        $attempt = DB::table('owner_connect_attempts')->sole();
        $owner->update(['email' => 'changed-pg@example.test']);
        $this->assertSame(200, $this->workerResult($this->worker('lost-retry', 'connect', $owner))['status']);
        $this->assertSame($attempt->idempotency_key, DB::table('owner_connect_attempts')->sole()->idempotency_key);
        $this->assertSame($attempt->parameters, DB::table('owner_connect_attempts')->sole()->parameters);
        $this->assertDatabaseCount('qa_accounts', 1);
        $this->assertNotNull($owner->fresh()->stripe_connect_account_id);
    }

    public function test_webhook_and_refresh_lock_prevent_stale_overwrites_and_allow_replay(): void
    {
        $owner = $this->readyOwner(['stripe_connect_status' => 'pending']);
        $a = $this->worker('event-a', 'webhook', $owner, null, ['pause' => 'read_account', 'event' => 'evt_pg_a']);
        $this->awaitSignal('read_account:event-a');
        DB::table('qa_remote_accounts')->insert(['id' => $owner->stripe_connect_account_id, 'active' => false]);
        $this->assertSame(503, $this->workerResult($this->worker('event-b', 'webhook', $owner, null, ['event' => 'evt_pg_b']))['status']);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->release('event-a');
        $this->assertSame(200, $this->workerResult($a)['status']);
        $this->assertSame(200, $this->workerResult($this->worker('event-b-retry', 'webhook', $owner, null, ['event' => 'evt_pg_b']))['status']);
        $this->assertSame('restricted', $owner->fresh()->stripe_connect_status);
        $this->assertSame(200, $this->workerResult($this->worker('event-b-replay', 'webhook', $owner, null, ['event' => 'evt_pg_b']))['status']);
        $this->assertDatabaseCount('stripe_events', 2);
        $this->assertDatabaseMissing('qa_signals', ['key' => 'read_account:event-b-replay']);
    }

    public function test_an_expired_lock_fences_off_the_old_process_after_another_one_recovers(): void
    {
        $owner = $this->readyOwner(['stripe_connect_account_id' => null, 'stripe_connect_status' => 'not_started']);
        $old = $this->worker('expired-lock', 'connect', $owner, null, ['pause' => 'account']);
        $this->awaitSignal('account:expired-lock');
        $lock = DB::table('cache_locks')->sole();
        DB::table('cache_locks')->where('key', $lock->key)->update(['expiration' => time() - 1]);
        $this->assertSame(200, $this->workerResult($this->worker('new-lock', 'connect', $owner))['status']);
        $confirmedId = $owner->fresh()->stripe_connect_account_id;
        $this->release('expired-lock');
        $this->assertSame(503, $this->workerResult($old)['status']);
        $this->assertSame($confirmedId, $owner->fresh()->stripe_connect_account_id);
        $this->assertDatabaseCount('qa_accounts', 1);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
    }

    public function test_process_termination_preserves_the_attempt_and_recovers_after_lock_expiry(): void
    {
        $owner = $this->readyOwner(['stripe_connect_account_id' => null, 'stripe_connect_status' => 'not_started']);
        $crashed = $this->worker('terminated', 'connect', $owner, null, ['pause' => 'account']);
        $this->awaitSignal('account:terminated');
        $attempt = DB::table('owner_connect_attempts')->sole();
        $crashed->stop(0);
        $this->assertFalse($crashed->isRunning());
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertSame(503, $this->workerResult($this->worker('before-expiry', 'connect', $owner))['status']);
        $lock = DB::table('cache_locks')->sole();
        DB::table('cache_locks')->where('key', $lock->key)->update(['expiration' => time() - 1]);
        $this->assertSame(200, $this->workerResult($this->worker('after-expiry', 'connect', $owner))['status']);
        $this->assertSame($attempt->idempotency_key, DB::table('owner_connect_attempts')->sole()->idempotency_key);
        $this->assertSame(DB::table('qa_accounts')->sole()->id, $owner->fresh()->stripe_connect_account_id);
        $this->assertDatabaseCount('qa_accounts', 1);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
    }

    private function assertNoPublication(): void
    {
        foreach (['groups', 'stripe_prices', 'group_members', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function assertPublishedOnce(GroupDraft $draft): void
    {
        foreach (['groups', 'stripe_prices', 'group_members'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertSame(Group::sole()->id, $draft->fresh()->published_group_id);
        $this->assertDatabaseCount('payments', 0);
    }
}
