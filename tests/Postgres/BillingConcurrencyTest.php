<?php

namespace Tests\Postgres;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\StripePrice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class BillingConcurrencyTest extends PostgresTestCase
{
    /** @var list<Process> */
    private array $billingWorkers = [];

    private const MIGRATIONS = [
        'admin' => '2026_10_06_130000_add_admin_role_to_users_table.php',
        'billing' => '2026_10_06_130100_create_billing_security_state.php',
    ];

    private const NEW_COLUMNS = [
        'users' => ['is_admin', 'auth_version'],
        'group_members' => ['cancellation_requested_at'],
        'payments' => ['stripe_invoice_id', 'confirmation_notified_at', 'credentials_check_due_at', 'credentials_check_queued_at'],
    ];

    protected function setUp(): void
    {
        parent::setUp(); // Includes actual database/role/data_directory/socket guard.
        Schema::create('qa_billing_remote', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('remote_id')->unique();
            $table->string('operation');
            $table->json('parameters');
            $table->json('state');
        });
        Schema::create('qa_billing_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('worker');
            $table->string('operation');
            $table->string('idempotency_key')->nullable();
            $table->json('parameters');
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->billingWorkers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
        parent::tearDown();
    }

    private function billingWorker(string $name, string $action, int $userId, int $targetId, array $options = []): Process
    {
        $process = new Process([
            PHP_BINARY, base_path('tests/Support/billing-concurrency-worker.php'),
            $name, $action, (string) $userId, (string) $targetId, json_encode($options, JSON_THROW_ON_ERROR),
        ], base_path());
        $process->setTimeout(25);
        $process->start();
        $this->billingWorkers[] = $process;

        return $process;
    }

    private function group(int $capacity = 6): Group
    {
        $owner = $this->readyOwner();
        $group = Group::factory()->withCredentials()->create([
            'owner_id' => $owner->id, 'current_members' => 1, 'max_members' => $capacity, 'total_price' => 2000,
        ]);
        GroupMember::factory()->owner()->create(['group_id' => $group->id, 'user_id' => $owner->id, 'share_amount' => 2000]);
        StripePrice::create([
            'group_id' => $group->id, 'stripe_product_id' => 'prod_pg_billing_'.$group->id,
            'stripe_price_id' => 'price_pg_initial_'.$group->id, 'unit_amount' => 2000, 'currency' => 'CAD',
        ]);

        return $group;
    }

    private function remote(string $id, string $operation, array $state): void
    {
        DB::table('qa_billing_remote')->insert([
            'key' => 'fixture:'.$id, 'remote_id' => $id, 'operation' => $operation,
            'parameters' => '{}', 'state' => json_encode(['id' => $id, ...$state], JSON_THROW_ON_ERROR),
        ]);
    }

    private function setRemoteState(string $id, array $changes): void
    {
        $row = DB::table('qa_billing_remote')->where('remote_id', $id)->sole();
        $state = json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
        DB::table('qa_billing_remote')->where('remote_id', $id)->update(['state' => json_encode([...$state, ...$changes], JSON_THROW_ON_ERROR)]);
    }

    /** @return array{GroupMember, Payment} */
    private function paidMember(): array
    {
        $group = $this->group();
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_pg_existing']);
        $member = GroupMember::factory()->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'status' => 'active', 'share_amount' => 1000,
            'stripe_subscription_id' => 'sub_pg_existing', 'stripe_customer_id' => $payer->stripe_customer_id,
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
        ]);
        $group->update(['current_members' => 2]);
        $payment = Payment::factory()->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'amount' => 1000,
            'stripe_payment_intent_id' => 'pi_pg_existing',
        ]);
        $this->remote('cus_pg_existing', 'customer', ['object' => 'customer']);
        $this->remote('sub_pg_existing', 'subscription', [
            'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_pg_existing', 'latest_invoice' => null,
        ]);
        $this->remote('pi_pg_existing', 'intent', [
            'object' => 'payment_intent', 'status' => 'succeeded', 'customer' => 'cus_pg_existing', 'currency' => 'cad',
            'amount_received' => 1000, 'latest_charge' => ['id' => 'ch_pg_existing', 'refunded' => false, 'amount_refunded' => 0],
        ]);

        return [$member, $payment];
    }

    private function calls(string $operation): array
    {
        return DB::table('qa_billing_calls')->where('operation', $operation)->orderBy('id')->get()
            ->map(fn ($row) => ['key' => $row->idempotency_key, 'parameters' => json_decode($row->parameters, true, flags: JSON_THROW_ON_ERROR)])->all();
    }

    private function assertRemoteCount(string $operation, int $count): void
    {
        $this->assertSame($count, DB::table('qa_billing_remote')->where('operation', $operation)->count());
    }

    private function expireGroupLease(Group $group): void
    {
        $this->assertSame(1, DB::table('cache_locks')->where('key', 'like', '%billing:group:'.$group->id)
            ->update(['expiration' => time() - 1]));
    }

    public function test_duplicate_checkout_from_independent_processes_reserves_and_creates_once(): void
    {
        $group = $this->group();
        $payer = User::factory()->create();
        $a = $this->billingWorker('checkout-a', 'checkout', $payer->id, $group->id, ['pause' => 'subscription']);
        $this->awaitSignal('subscription:checkout-a');
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertSame('pending_payment', $group->members()->where('user_id', $payer->id)->sole()->status);
        $this->assertSame(503, $this->workerResult($this->billingWorker('checkout-b', 'checkout', $payer->id, $group->id))['status']);
        DB::transaction(fn () => Group::whereKey($group->id)->lockForUpdate()->firstOrFail());
        $this->release('checkout-a');
        $first = $this->workerResult($a);
        $this->assertSame(200, $first['status']);
        $this->assertSame($first, $this->workerResult($this->billingWorker('checkout-replay', 'checkout', $payer->id, $group->id)));
        $this->assertRemoteCount('customer', 1);
        $this->assertRemoteCount('price', 1);
        $this->assertRemoteCount('subscription', 1);
        $this->assertCount(1, $this->calls('subscription'));
        $this->assertSame($first['result']['subscription_id'], DB::table('subscription_attempts')->sole()->subscription_id);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_two_payers_cannot_both_take_the_last_seat(): void
    {
        $group = $this->group(2);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $a = $this->billingWorker('last-seat-a', 'checkout', $first->id, $group->id, ['pause' => 'subscription']);
        $this->awaitSignal('subscription:last-seat-a');
        $this->assertSame('full', $group->fresh()->status);
        $this->assertSame(503, $this->workerResult($this->billingWorker('last-seat-b', 'checkout', $second->id, $group->id))['status']);
        $this->release('last-seat-a');
        $this->assertSame(200, $this->workerResult($a)['status']);
        $this->assertSame(409, $this->workerResult($this->billingWorker('last-seat-b-retry', 'checkout', $second->id, $group->id))['status']);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertSame(2, $group->members()->count());
        $this->assertDatabaseMissing('group_members', ['user_id' => $second->id, 'group_id' => $group->id]);
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertRemoteCount('subscription', 1);
        $this->assertRemoteCount('customer', 1);
    }

    public function test_join_and_checkout_share_the_last_seat_lock_across_processes(): void
    {
        $group = $this->group(2);
        $payer = User::factory()->create();
        $joiner = User::factory()->create();
        $checkout = $this->billingWorker('checkout-before-reservation', 'checkout', $payer->id, $group->id, ['pause' => 'account']);
        $this->awaitSignal('account:checkout-before-reservation');
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame(503, $this->workerResult($this->billingWorker('join-racing', 'join', $joiner->id, $group->id))['status']);
        $this->release('checkout-before-reservation');
        $this->assertSame(200, $this->workerResult($checkout)['status']);
        $this->assertSame(409, $this->workerResult($this->billingWorker('join-after-checkout', 'join', $joiner->id, $group->id))['status']);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id, 'user_id' => $joiner->id]);
        $this->assertRemoteCount('subscription', 1);
    }

    #[DataProvider('lostCheckoutBoundaries')]
    public function test_lost_checkout_responses_reuse_durable_keys_and_immutable_parameters(string $point): void
    {
        $group = $this->group();
        $payer = User::factory()->create(['email' => 'original-pg@example.test', 'name' => 'Original QA']);
        $first = $this->workerResult($this->billingWorker('lost', 'checkout', $payer->id, $group->id, ['lost_response' => $point]));
        $this->assertSame(503, $first['status']);
        $attempt = DB::table('subscription_attempts')->sole();
        $customerAttempt = DB::table('billing_customer_attempts')->sole();
        $this->assertNull($attempt->subscription_id);
        $payer->update(['email' => 'changed-pg@example.test', 'name' => 'Changed QA']);
        $group->update(['total_price' => 8888]);
        $this->assertSame(200, $this->workerResult($this->billingWorker('recovered', 'checkout', $payer->id, $group->id, ['method' => 'pm_changed']))['status']);
        $this->assertSame($attempt->id, DB::table('subscription_attempts')->sole()->id);
        $this->assertSame($attempt->parameters, DB::table('subscription_attempts')->sole()->parameters);
        $this->assertSame($customerAttempt->idempotency_key, DB::table('billing_customer_attempts')->sole()->idempotency_key);
        $this->assertSame($customerAttempt->parameters, DB::table('billing_customer_attempts')->sole()->parameters);
        $calls = $this->calls($point);
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0], $calls[1]);
        $this->assertNotEmpty($calls[0]['key']);
        foreach (['customer', 'price', 'subscription'] as $operation) {
            $this->assertRemoteCount($operation, 1);
        }
        $member = $group->members()->where('user_id', $payer->id)->sole();
        $this->assertSame(DB::table('subscription_attempts')->sole()->subscription_id, $member->stripe_subscription_id);
        $this->assertSame(1000, $member->share_amount);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertStringNotContainsString('pm_pg', $attempt->parameters);
        $this->assertSame('pm_pg', json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR)['method']);
    }

    public static function lostCheckoutBoundaries(): array
    {
        return [['customer'], ['price'], ['subscription']];
    }

    public function test_expired_ambiguous_checkout_does_not_create_a_new_subscription(): void
    {
        $group = $this->group();
        $payer = User::factory()->create();
        $this->assertSame(503, $this->workerResult($this->billingWorker('ambiguous', 'checkout', $payer->id, $group->id, ['lost_response' => 'subscription']))['status']);
        DB::table('subscription_attempts')->update(['started_at' => now()->subHours(24)]);
        $attempt = (array) DB::table('subscription_attempts')->sole();
        $this->assertSame(503, $this->workerResult($this->billingWorker('expired', 'checkout', $payer->id, $group->id))['status']);
        $this->assertSame($attempt, (array) DB::table('subscription_attempts')->sole());
        $this->assertCount(1, $this->calls('subscription'));
        $this->assertRemoteCount('subscription', 1);
        $this->assertSame(2, $group->fresh()->current_members);
    }

    public function test_process_death_preserves_attempt_and_recovery_after_lease_expiry(): void
    {
        $group = $this->group();
        $payer = User::factory()->create();
        $a = $this->billingWorker('killed', 'checkout', $payer->id, $group->id, ['pause' => 'subscription']);
        $this->awaitSignal('subscription:killed');
        $attempt = DB::table('subscription_attempts')->sole();
        $a->stop(0);
        $this->assertFalse($a->isRunning());
        $this->assertSame(503, $this->workerResult($this->billingWorker('before-expiry', 'checkout', $payer->id, $group->id))['status']);
        $this->expireGroupLease($group);
        $this->assertSame(200, $this->workerResult($this->billingWorker('after-expiry', 'checkout', $payer->id, $group->id))['status']);
        $this->assertSame($attempt->id, DB::table('subscription_attempts')->sole()->id);
        $this->assertSame($this->calls('subscription')[0], $this->calls('subscription')[1]);
        $this->assertRemoteCount('subscription', 1);
        $this->assertSame(2, $group->fresh()->current_members);
    }

    public function test_expired_worker_cannot_overwrite_checkout_completed_by_recovery_process(): void
    {
        $group = $this->group();
        $payer = User::factory()->create();
        $old = $this->billingWorker('old-lease', 'checkout', $payer->id, $group->id, ['pause' => 'subscription']);
        $this->awaitSignal('subscription:old-lease');
        $this->expireGroupLease($group);
        $new = $this->workerResult($this->billingWorker('new-lease', 'checkout', $payer->id, $group->id));
        $this->assertSame(200, $new['status']);
        $confirmed = $group->members()->where('user_id', $payer->id)->sole()->stripe_subscription_id;
        $this->release('old-lease');
        $this->assertSame(503, $this->workerResult($old)['status']);
        $this->assertSame($confirmed, $group->members()->where('user_id', $payer->id)->sole()->stripe_subscription_id);
        $this->assertSame($new['result']['subscription_id'], $confirmed);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertRemoteCount('subscription', 1);
    }

    public function test_sql_failure_after_remote_subscription_preserves_recoverable_attempt(): void
    {
        $group = $this->group();
        $payer = User::factory()->create();
        DB::statement('ALTER TABLE group_members ADD CONSTRAINT qa_reject_subscription CHECK (stripe_subscription_id IS NULL) NOT VALID');
        $this->assertSame(['status' => 500, 'sqlstate' => '23514'], $this->workerResult($this->billingWorker('sql-failed', 'checkout', $payer->id, $group->id)));
        $attempt = DB::table('subscription_attempts')->sole();
        $this->assertNull($attempt->subscription_id);
        $this->assertNull($group->members()->where('user_id', $payer->id)->sole()->stripe_subscription_id);
        $this->assertRemoteCount('subscription', 1);
        DB::statement('ALTER TABLE group_members DROP CONSTRAINT qa_reject_subscription');
        $this->assertSame(200, $this->workerResult($this->billingWorker('sql-recovered', 'checkout', $payer->id, $group->id))['status']);
        $this->assertSame($attempt->id, DB::table('subscription_attempts')->sole()->id);
        $this->assertSame($this->calls('subscription')[0], $this->calls('subscription')[1]);
        $this->assertRemoteCount('subscription', 1);
        $this->assertSame(2, $group->fresh()->current_members);
    }

    public function test_concurrent_refunds_issue_one_refund_and_release_the_seat_once(): void
    {
        [$member, $payment] = $this->paidMember();
        $a = $this->billingWorker('refund-a', 'refund', $member->user_id, $payment->id, ['pause' => 'refund']);
        $this->awaitSignal('refund:refund-a');
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(1, $member->group->fresh()->current_members);
        $this->assertSame(503, $this->workerResult($this->billingWorker('refund-b', 'refund', $member->user_id, $payment->id))['status']);
        $this->release('refund-a');
        $this->assertSame(200, $this->workerResult($a)['status']);
        $this->assertSame(200, $this->workerResult($this->billingWorker('refund-replay', 'refund', $member->user_id, $payment->id))['status']);
        $this->assertRemoteCount('refund', 1);
        $this->assertCount(1, $this->calls('refund'));
        $this->assertCount(1, $this->calls('cancel'));
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $member->group->fresh()->current_members);
        $this->assertDatabaseCount('payment_refund_attempts', 1);
    }

    public function test_lost_refund_response_reuses_the_persisted_key_and_reason(): void
    {
        [$member, $payment] = $this->paidMember();
        $this->assertSame(503, $this->workerResult($this->billingWorker('lost-refund', 'refund', $member->user_id, $payment->id, ['lost_response' => 'refund']))['status']);
        $attempt = DB::table('payment_refund_attempts')->sole();
        $this->assertNull($attempt->refund_id);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->refunded_at);
        $this->assertSame(200, $this->workerResult($this->billingWorker('refund-retry', 'refund', $member->user_id, $payment->id, ['reason' => 'changed_reason']))['status']);
        $this->assertSame($attempt->idempotency_key, DB::table('payment_refund_attempts')->sole()->idempotency_key);
        $this->assertSame($this->calls('refund')[0], $this->calls('refund')[1]);
        $this->assertRemoteCount('refund', 1);
        $this->assertSame('qa_refund', $payment->fresh()->refund_reason);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_pending_refund_is_not_terminal_and_reconciles_without_a_second_creation(): void
    {
        [$member, $payment] = $this->paidMember();
        $this->assertSame(200, $this->workerResult($this->billingWorker('pending', 'refund', $member->user_id, $payment->id, ['refund_status' => 'pending']))['status']);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->refunded_at);
        $this->assertSame('pending', DB::table('payment_refund_attempts')->sole()->status);
        $this->setRemoteState($payment->fresh()->stripe_refund_id, ['status' => 'succeeded']);
        $this->assertSame(200, $this->workerResult($this->billingWorker('confirmed', 'refund', $member->user_id, $payment->id))['status']);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
        $this->assertSame('succeeded', DB::table('payment_refund_attempts')->sole()->status);
        $this->assertCount(1, $this->calls('refund'));
        $this->assertCount(1, $this->calls('read_refund'));
        $this->assertRemoteCount('refund', 1);
    }

    public function test_completed_refund_still_retries_unconfirmed_cancellation(): void
    {
        [$member, $payment] = $this->paidMember();
        $this->assertSame(200, $this->workerResult($this->billingWorker('cancel-timeout', 'refund', $member->user_id, $payment->id, ['cancel_failure' => true]))['status']);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('cancellation_pending', $member->fresh()->subscription_status);
        $this->assertSame('left', $member->fresh()->status);
        $requested = $member->fresh()->cancellation_requested_at;
        $this->assertNotNull($requested);
        $this->assertSame(200, $this->workerResult($this->billingWorker('cancel-retry', 'refund', $member->user_id, $payment->id))['status']);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertTrue($requested->equalTo($member->fresh()->cancellation_requested_at));
        $this->assertCount(2, $this->calls('cancel'));
        $this->assertCount(1, $this->calls('refund'));
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_parallel_cancellations_have_one_external_effect_and_one_decrement(): void
    {
        [$member] = $this->paidMember();
        $a = $this->billingWorker('cancel-a', 'cancel', $member->user_id, $member->id, ['pause' => 'cancel']);
        $this->awaitSignal('cancel:cancel-a');
        $this->assertSame('cancellation_pending', $member->fresh()->subscription_status);
        $this->assertSame(503, $this->workerResult($this->billingWorker('cancel-b', 'cancel', $member->user_id, $member->id))['status']);
        $this->release('cancel-a');
        $this->assertSame(['status' => 200, 'result' => ['confirmed' => true]], $this->workerResult($a));
        $this->assertSame(['status' => 200, 'result' => ['confirmed' => true]], $this->workerResult($this->billingWorker('cancel-replay', 'cancel', $member->user_id, $member->id)));
        $this->assertCount(1, $this->calls('cancel'));
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    private function canceledEvent(): array
    {
        return ['id' => 'evt_pg_canceled', 'object' => 'event', 'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_pg_existing', 'object' => 'subscription', 'status' => 'canceled']]];
    }

    public function test_same_webhook_in_parallel_records_one_receipt_after_effects(): void
    {
        [$member] = $this->paidMember();
        $this->setRemoteState('sub_pg_existing', ['status' => 'canceled']);
        $options = ['event' => $this->canceledEvent()];
        $a = $this->billingWorker('event-a', 'event', $member->user_id, 0, [...$options, 'pause' => 'read_subscription']);
        $this->awaitSignal('read_subscription:event-a');
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame(503, $this->workerResult($this->billingWorker('event-b', 'event', $member->user_id, 0, $options))['status']);
        $this->release('event-a');
        $this->assertSame(200, $this->workerResult($a)['status']);
        $this->assertSame(200, $this->workerResult($this->billingWorker('event-replay', 'event', $member->user_id, 0, $options))['status']);
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertNotNull(DB::table('stripe_events')->sole()->processed_at);
        $this->assertCount(1, $this->calls('read_subscription'));
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_receipt_sql_failure_replays_after_committed_cancellation_without_double_decrement(): void
    {
        [$member] = $this->paidMember();
        $this->setRemoteState('sub_pg_existing', ['status' => 'canceled']);
        DB::statement("ALTER TABLE stripe_events ADD CONSTRAINT qa_reject_receipt CHECK (type <> 'customer.subscription.deleted') NOT VALID");
        $options = ['event' => $this->canceledEvent()];
        $this->assertSame(['status' => 503], $this->workerResult($this->billingWorker('receipt-failed', 'event', $member->user_id, 0, $options)));
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(1, $member->group->fresh()->current_members);
        $this->assertDatabaseCount('stripe_events', 0);
        DB::statement('ALTER TABLE stripe_events DROP CONSTRAINT qa_reject_receipt');
        $this->assertSame(200, $this->workerResult($this->billingWorker('receipt-retry', 'event', $member->user_id, 0, $options))['status']);
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertCount(2, $this->calls('read_subscription'));
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_different_paid_events_racing_for_one_invoice_create_one_payment_and_one_credit_count(): void
    {
        [$member, $fixturePayment] = $this->paidMember();
        $fixturePayment->delete(); // Only this synthetic fixture in the guarded disposable database.
        $member->update(['status' => 'pending_payment']);
        $this->setRemoteState('sub_pg_existing', [
            'currency' => 'cad', 'latest_invoice' => 'in_pg_current',
            'items' => ['data' => [['id' => 'si_pg_existing', 'current_period_end' => now()->addMonth()->timestamp]]],
        ]);
        $invoice = [
            'id' => 'in_pg_current', 'object' => 'invoice', 'customer' => 'cus_pg_existing',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_pg_existing']],
            'status' => 'paid', 'currency' => 'cad', 'amount_paid' => 1000, 'amount_due' => 1000, 'amount_remaining' => 0,
            'period_start' => now()->subDay()->timestamp, 'period_end' => now()->addMonth()->timestamp,
            'status_transitions' => ['paid_at' => now()->timestamp],
        ];
        $invoicePayment = [
            'id' => 'inpay_pg_current', 'object' => 'invoice_payment', 'invoice' => 'in_pg_current',
            'status' => 'paid', 'amount_paid' => 1000,
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_pg_existing'],
        ];
        $this->remote('in_pg_current', 'invoice', $invoice);
        $this->remote('inpay_pg_current', 'invoice_payment', $invoicePayment);
        $firstEvent = ['id' => 'evt_pg_invoice_paid', 'object' => 'event', 'type' => 'invoice.paid', 'data' => ['object' => $invoice]];
        $secondEvent = ['id' => 'evt_pg_invoice_payment', 'object' => 'event', 'type' => 'invoice_payment.paid', 'data' => ['object' => $invoicePayment]];
        $a = $this->billingWorker('paid-a', 'event', $member->user_id, 0, ['event' => $firstEvent, 'pause' => 'read_intent']);
        $this->awaitSignal('read_intent:paid-a');
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('pending_payment', $member->fresh()->status);
        $this->assertSame(503, $this->workerResult($this->billingWorker('paid-b', 'event', $member->user_id, 0, ['event' => $secondEvent]))['status']);
        $this->release('paid-a');
        $this->assertSame(200, $this->workerResult($a)['status']);
        $this->assertSame(200, $this->workerResult($this->billingWorker('paid-b-retry', 'event', $member->user_id, 0, ['event' => $secondEvent]))['status']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['stripe_invoice_id' => 'in_pg_current', 'stripe_payment_intent_id' => 'pi_pg_existing', 'status' => 'completed', 'amount' => 1000]);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame(1, $member->user->fresh()->completed_payments_count);
        $this->assertSame(2, $member->group->fresh()->current_members);
        $this->assertDatabaseCount('stripe_events', 2);
        $this->assertCount(2, $this->calls('read_intent'));
    }

    public static function migrationScopes(): array
    {
        return [['admin'], ['billing'], ['both']];
    }

    #[DataProvider('migrationScopes')]
    public function test_additive_security_migrations_preserve_legacy_schema_and_data_on_upgrade_rollback_and_reapply(string $scope): void
    {
        [$member, $payment] = $this->paidMember();
        $this->draftFor($member->group->owner);
        Payment::factory()->refunded()->create(['group_id' => $member->group_id, 'user_id' => $member->user_id]);
        $this->populateBillingState($member, $payment);
        $migrationNames = $scope === 'both' ? ['admin', 'billing'] : [$scope];
        $migrations = [];
        foreach ($migrationNames as $name) {
            $migrations[$name] = require database_path('migrations/'.self::MIGRATIONS[$name]);
        }
        $expected = $this->legacySnapshot($migrationNames);
        $passwordCipher = DB::table('groups')->where('id', $member->group_id)->value('credential_password');
        foreach (array_reverse($migrations, true) as $migration) {
            $migration->down();
        }
        $this->assertSame($expected, $this->legacySnapshot($migrationNames));
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertSame($expected, $this->legacySnapshot($migrationNames));
        if (in_array('admin', $migrationNames, true)) {
            $this->assertFalse((bool) DB::table('users')->where('id', $member->user_id)->value('is_admin'));
            $this->assertSame(0, DB::table('users')->where('id', $member->user_id)->value('auth_version'));
        }
        if (in_array('billing', $migrationNames, true)) {
            foreach (['billing_customer_attempts', 'subscription_attempts', 'payment_refund_attempts'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertNull(DB::table('group_members')->where('id', $member->id)->value('cancellation_requested_at'));
            $this->assertNull(DB::table('payments')->where('id', $payment->id)->value('stripe_invoice_id'));
            $this->assertNull(DB::table('payments')->where('id', $payment->id)->value('confirmation_notified_at'));
            $this->assertNull(DB::table('payments')->where('id', $payment->id)->value('credentials_check_queued_at'));
            $this->assertNull(DB::table('payments')->where('id', $payment->id)->value('credentials_check_due_at'));
            $this->populateBillingState($member, $payment);
        }
        foreach (array_reverse($migrations, true) as $migration) {
            $migration->down();
        }
        $this->assertSame($expected, $this->legacySnapshot($migrationNames));
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertSame($expected, $this->legacySnapshot($migrationNames));
        $this->assertSame($passwordCipher, DB::table('groups')->where('id', $member->group_id)->value('credential_password'));
        $this->assertSame('super-secret-password', $member->group->fresh()->credential_password);
        $this->assertSame(2, $member->group->fresh()->current_members);
        $this->assertSame(2, Payment::where('group_id', $member->group_id)->count());
    }

    private function populateBillingState(GroupMember $member, Payment $payment): void
    {
        DB::table('billing_customer_attempts')->insert([
            'user_id' => $member->user_id, 'idempotency_key' => (string) Str::uuid(),
            'parameters' => Crypt::encryptString('{"email":"pg-fixture@example.test"}'), 'started_at' => now(),
        ]);
        DB::table('subscription_attempts')->insert([
            'id' => (string) Str::uuid(), 'group_id' => $member->group_id, 'user_id' => $member->user_id,
            'parameters' => Crypt::encryptString('{"method":"pm_pg_fixture"}'), 'started_at' => now(),
            'price_id' => 'price_pg_fixture', 'subscription_id' => $member->stripe_subscription_id,
        ]);
        DB::table('payment_refund_attempts')->insert([
            'payment_id' => $payment->id, 'idempotency_key' => (string) Str::uuid(), 'refund_id' => 're_pg_fixture',
            'status' => 'pending', 'reason' => 'pg_migration', 'started_at' => now(),
        ]);
        DB::table('group_members')->where('id', $member->id)->update(['cancellation_requested_at' => now()]);
        DB::table('payments')->where('id', $payment->id)->update([
            'stripe_invoice_id' => 'in_pg_fixture', 'confirmation_notified_at' => now(),
            'credentials_check_due_at' => now()->addHours(48), 'credentials_check_queued_at' => now(),
        ]);
    }

    private function legacySnapshot(array $migrated): array
    {
        $tables = ['users', 'subscription_categories', 'subscriptions', 'groups', 'group_members', 'payments', 'stripe_prices', 'wallets', 'transactions', 'migrations', 'group_drafts', 'owner_connect_attempts'];
        if (! in_array('billing', $migrated, true)) {
            $tables = [...$tables, 'billing_customer_attempts', 'subscription_attempts', 'payment_refund_attempts'];
        }
        $snapshot = [];
        foreach ($tables as $table) {
            $exclude = match ($table) {
                'users' => in_array('admin', $migrated, true) ? self::NEW_COLUMNS[$table] : [],
                'payments', 'group_members' => in_array('billing', $migrated, true) ? self::NEW_COLUMNS[$table] : [],
                default => [],
            };
            $columns = array_values(array_filter(Schema::getColumns($table), fn ($column) => ! in_array($column['name'], $exclude, true)));
            // PostgreSQL retains attribute-number gaps after DROP COLUMN;
            // compare each logical column definition, not physical positions.
            $indexes = collect(Schema::getIndexes($table))->reject(fn ($index) => array_intersect($index['columns'], $exclude) !== [])->sortBy('name')->values()->all();
            $keys = collect(Schema::getForeignKeys($table))->sortBy('name')->values()->all();
            $order = match ($table) {
                'owner_connect_attempts', 'billing_customer_attempts' => 'user_id',
                'payment_refund_attempts' => 'payment_id', default => 'id',
            };
            $rows = DB::table($table)->orderBy($order)->get()->map(fn ($row) => array_diff_key((array) $row, array_flip($exclude)))->all();
            $snapshot[$table] = ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $keys, 'rows' => $rows];
        }

        return $snapshot;
    }

    #[DataProvider('historicalAdminStates')]
    public function test_admin_upgrade_grants_only_the_verified_non_deleted_non_banned_historical_account(bool $verified, bool $deleted, string $status, bool $expected): void
    {
        $migration = require database_path('migrations/'.self::MIGRATIONS['admin']);
        $migration->down();
        $user = User::factory()->create([
            'email' => 'briceyouatchui@gmail.com', 'email_verified_at' => $verified ? now() : null,
            'deleted_at' => $deleted ? now() : null, 'status' => $status,
        ]);
        $other = User::factory()->create();
        $migration->up();
        $this->assertSame($expected, (bool) DB::table('users')->where('id', $user->id)->value('is_admin'));
        $this->assertFalse((bool) DB::table('users')->where('id', $other->id)->value('is_admin'));
        $this->assertSame(0, DB::table('users')->where('id', $user->id)->value('auth_version'));
    }

    public static function historicalAdminStates(): array
    {
        return [[true, false, 'active', true], [false, false, 'active', false], [true, true, 'active', false], [true, false, 'banned', false]];
    }

    public function test_native_billing_constraints_reject_duplicates_and_invalid_references(): void
    {
        [$member, $payment] = $this->paidMember();
        $this->populateBillingState($member, $payment);
        $subscription = (array) DB::table('subscription_attempts')->sole();
        $customer = (array) DB::table('billing_customer_attempts')->sole();
        $refund = (array) DB::table('payment_refund_attempts')->sole();
        $this->assertSame('uuid', Schema::getColumnType('subscription_attempts', 'id'));
        $this->assertSame('uuid', Schema::getColumnType('billing_customer_attempts', 'idempotency_key'));
        $this->assertSame('uuid', Schema::getColumnType('payment_refund_attempts', 'idempotency_key'));
        $this->assertSqlState('23505', fn () => DB::table('subscription_attempts')->insert([...$subscription, 'id' => (string) Str::uuid(), 'subscription_id' => null]));
        $this->assertSqlState('23505', fn () => DB::table('billing_customer_attempts')->insert([...$customer, 'idempotency_key' => (string) Str::uuid()]));
        $this->assertSqlState('23505', fn () => DB::table('payment_refund_attempts')->insert([...$refund, 'idempotency_key' => (string) Str::uuid(), 'refund_id' => null]));
        $this->assertSqlState('22P02', fn () => DB::table('subscription_attempts')->insert([...$subscription, 'id' => 'malformed']));
        $missingUser = (int) DB::table('users')->max('id') + 100;
        $this->assertSqlState('23503', fn () => DB::table('billing_customer_attempts')->insert([...$customer, 'user_id' => $missingUser, 'idempotency_key' => (string) Str::uuid()]));
        $missingPayment = (int) DB::table('payments')->max('id') + 100;
        $this->assertSqlState('23503', fn () => DB::table('payment_refund_attempts')->insert([...$refund, 'payment_id' => $missingPayment, 'idempotency_key' => (string) Str::uuid(), 'refund_id' => null]));
        $other = Payment::factory()->create(['group_id' => $member->group_id, 'user_id' => $member->user_id]);
        $this->assertSqlState('23505', fn () => DB::table('payments')->where('id', $other->id)->update(['stripe_invoice_id' => 'in_pg_fixture']));
        $this->assertSame($subscription, (array) DB::table('subscription_attempts')->sole());
        $this->assertSame($customer, (array) DB::table('billing_customer_attempts')->sole());
        $this->assertSame($refund, (array) DB::table('payment_refund_attempts')->sole());
        $this->assertNull($other->fresh()->stripe_invoice_id);
    }

    private function assertSqlState(string $expected, callable $write): void
    {
        $this->assertSame(0, DB::transactionLevel());
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception->errorInfo[0]);

            return;
        }
        $this->fail('PostgreSQL accepted an invalid write; expected '.$expected);
    }
}
