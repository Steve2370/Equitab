<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingReconciliationService;
use App\Features\Payment\Services\BillingUnavailable;
use App\Jobs\CheckCredentialsProvided;
use App\Mail\AutoRefundProcessed;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class BillingRecoveryTest extends TestCase
{
    private MockInterface $gateway;

    private MockInterface $reader;

    private array $originalEnvironment = [];

    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'APP_CONFIG_CACHE' => '/private/tmp/equitab-recovery-no-config.php',
            'APP_ROUTES_CACHE' => '/private/tmp/equitab-recovery-no-routes.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
            'STRIPE_SECRET' => 'sk_test_recovery_no_network',
        ] as $name => $value) {
            $this->originalEnvironment[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/no-environment-files');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertEmpty(config('database.connections.sqlite.url'));
        config([
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.lock_table' => 'cache_locks',
            'logging.default' => 'billing_recovery',
            'logging.channels.billing_recovery' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Http::preventStrayRequests();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        $this->withoutVite();
        $original = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new RuntimeException('Stripe network forbidden.'));
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
        $this->gateway = Mockery::mock(PaymentGatewayInterface::class);
        $this->reader = Mockery::mock(BillingReadGatewayInterface::class);
        $this->instance(PaymentGatewayInterface::class, $this->gateway);
        $this->instance(BillingReadGatewayInterface::class, $this->reader);
        // No wrapping transaction: external boundaries must see committed intents.
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        try {
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        } finally {
            foreach ($this->originalEnvironment as $name => [$process, $env, $server]) {
                putenv($process === false ? $name : $name.'='.$process);
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    private function fixture(): array
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $payment = Payment::factory()->for($group)->create(['stripe_payment_intent_id' => 'pi_recovery']);
        $member = GroupMember::factory()->for($group)->for($payment->user, 'user')
            ->withStripeSubscription('sub_recovery')->create(['stripe_customer_id' => 'cus_recovery']);

        return [$payment, $member, $group];
    }

    private function job(Payment $payment): void
    {
        (new CheckCredentialsProvided($payment->id, $payment->group_id, $payment->user_id))
            ->handle(app(BillingReconciliationService::class));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function dispute(Payment $payment): Dispute
    {
        return Dispute::create(['payment_id' => $payment->id, 'user_id' => $payment->user_id,
            'group_id' => $payment->group_id, 'reason' => 'no_access', 'status' => 'open']);
    }

    private function attempt(Payment $payment, string $status = 'pending', string $reason = 'auto_no_credentials'): void
    {
        DB::table('payment_refund_attempts')->insert([
            'payment_id' => $payment->id, 'idempotency_key' => (string) Str::uuid(),
            'refund_id' => $status === 'requested' ? null : 're_recovery',
            'status' => $status, 'reason' => $reason, 'started_at' => now(),
        ]);
    }

    private function paidSubscriptionSnapshot(GroupMember $member, int $reads = 1, string $invoiceId = 'in_recovery', string $intentId = 'pi_recovery'): int
    {
        $periodEnd = now()->addMonth()->timestamp;
        $this->reader->shouldReceive('retrieveSubscription')->times($reads)->with($member->stripe_subscription_id)->andReturn([
            'id' => $member->stripe_subscription_id, 'customer' => $member->stripe_customer_id,
            'status' => 'active', 'currency' => 'cad', 'latest_invoice' => $invoiceId,
            'items' => ['data' => [['current_period_end' => $periodEnd]]],
        ]);
        $this->reader->shouldReceive('retrieveInvoice')->times($reads)->with($invoiceId)->andReturn([
            'id' => $invoiceId, 'status' => 'paid', 'customer' => $member->stripe_customer_id,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => $member->stripe_subscription_id]],
            'currency' => 'cad', 'amount_paid' => 1999, 'amount_due' => 1999, 'amount_remaining' => 0,
            'period_start' => now()->timestamp, 'period_end' => $periodEnd,
            'status_transitions' => ['paid_at' => now()->timestamp],
        ]);
        $this->reader->shouldReceive('invoicePayments')->times($reads)->with($invoiceId)->andReturn([[
            'id' => 'inpay_recovery', 'invoice' => $invoiceId, 'status' => 'paid', 'amount_paid' => 1999,
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $intentId],
        ]]);
        $this->reader->shouldReceive('retrievePaymentIntent')->times($reads)->with($intentId)->andReturn([
            'id' => $intentId, 'status' => 'succeeded', 'customer' => $member->stripe_customer_id,
            'currency' => 'cad', 'amount_received' => 1999,
            'latest_charge' => ['id' => 'ch_recovery', 'refunded' => false, 'amount_refunded' => 0],
        ]);

        return $periodEnd;
    }

    public function test_pending_auto_refund_has_no_confirmation_or_owner_penalty(): void
    {
        [$payment, $member, $group] = $this->fixture();
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('sub_recovery');
        $this->gateway->shouldReceive('refundPayment')->once()->andReturnUsing(function () use ($member) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNotNull($member->fresh()->cancellation_requested_at);

            return ['refund_id' => 're_recovery', 'status' => 'pending'];
        });
        $this->job($payment);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(0, $group->owner->fresh()->disputed_payments_count);
        $this->assertDatabaseHas('payment_refund_attempts', ['payment_id' => $payment->id, 'status' => 'pending', 'notified_at' => null]);
        Mail::assertNothingSent();
    }

    public function test_queue_failure_stays_retryable_without_exposing_provider_secret(): void
    {
        [$payment] = $this->fixture();
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->gateway->shouldReceive('refundPayment')->once()->andThrow(new RuntimeException('PRIVATE_PROVIDER_SECRET'));
        try {
            $this->job($payment);
            $this->fail('Job must fail so the queue can retry.');
        } catch (BillingUnavailable $error) {
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SECRET', $error->getMessage());
        }
        $this->assertDatabaseHas('payment_refund_attempts', ['payment_id' => $payment->id, 'status' => 'requested']);
        $logs = Log::channel('billing_recovery')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_SECRET', json_encode($logs));
        Mail::assertNothingSent();
    }

    public function test_pending_refund_resumes_after_credentials_arrive_and_notifies_only_once(): void
    {
        [$payment, $member, $group] = $this->fixture();
        $this->attempt($payment);
        $group->update(['credential_email' => 'synthetic@example.test']);
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('sub_recovery');
        $this->reader->shouldReceive('retrieveRefund')->once()->with('re_recovery')
            ->andReturn(['id' => 're_recovery', 'payment_intent' => 'pi_recovery', 'status' => 'succeeded']);
        $this->job($payment);
        $this->job($payment);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $group->owner->fresh()->disputed_payments_count);
        $this->assertNotNull(DB::table('payment_refund_attempts')->value('notified_at'));
        Mail::assertSent(AutoRefundProcessed::class, 1);
    }

    public function test_cancellation_retries_after_soft_deletion_and_failure_returns_nonzero(): void
    {
        [$payment, $member, $group] = $this->fixture();
        $member->update(['cancellation_requested_at' => now()]);
        $payment->user->delete();
        $group->delete();
        $calls = 0;
        $this->gateway->shouldReceive('cancelSubscription')->twice()->andReturnUsing(function () use (&$calls, $member) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('left', $member->fresh()->status);
            if (++$calls === 1) {
                throw new RuntimeException('PRIVATE_PROVIDER_SECRET');
            }
        });
        $this->artisan('equitab:reconcile-payments')->assertExitCode(1);
        $this->assertSame('cancellation_pending', $member->fresh()->subscription_status);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, Group::withTrashed()->find($group->id)->current_members);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_admin_pending_refund_keeps_dispute_open_until_reconciliation(): void
    {
        [$payment, , $group] = $this->fixture();
        $dispute = $this->dispute($payment);
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->gateway->shouldReceive('refundPayment')->once()->andReturn(['refund_id' => 're_recovery', 'status' => 'pending']);
        $this->actingAs($this->admin())->patch('/admin/disputes/'.$dispute->id.'/resolve', [
            'status' => 'resolved_refund', 'admin_notes' => 'Reprise demandée',
        ])->assertRedirect()->assertSessionHas('success', 'Demande de remboursement enregistrée, confirmation en attente.');
        $this->assertSame('open', $dispute->fresh()->status);
        $this->assertNull($dispute->fresh()->resolved_at);
        Mail::assertNothingSent();
        $this->reader->shouldReceive('retrieveRefund')->once()->andReturn([
            'id' => 're_recovery', 'payment_intent' => 'pi_recovery', 'status' => 'succeeded',
        ]);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('resolved_refund', $dispute->fresh()->status);
        $this->assertSame('Reprise demandée', $dispute->fresh()->admin_notes);
        $this->assertSame(0, $group->owner->fresh()->disputed_payments_count);
        Mail::assertSent(AutoRefundProcessed::class, 1);
    }

    public function test_admin_refund_error_is_generic_and_does_not_resolve_dispute(): void
    {
        [$payment] = $this->fixture();
        $dispute = $this->dispute($payment);
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->gateway->shouldReceive('refundPayment')->once()->andThrow(new RuntimeException('PRIVATE_PROVIDER_SECRET'));
        $this->actingAs($this->admin())->patch('/admin/disputes/'.$dispute->id.'/resolve', ['status' => 'resolved_refund'])
            ->assertRedirect()->assertSessionHas('error', 'Le remboursement ne peut pas être confirmé ; la demande reste à suivre.');
        $this->assertSame('open', $dispute->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_admin_delete_persists_all_cancellations_including_owned_full_groups(): void
    {
        [$payment, $member, $group] = $this->fixture();
        $owner = $group->owner;
        $group->update(['status' => 'full', 'max_members' => 2]);
        $external = GroupMember::factory()->for($owner, 'user')->withStripeSubscription('sub_external')
            ->create(['status' => 'pending_payment']);
        $this->gateway->shouldReceive('cancelSubscription')->twice()->andReturnUsing(function () use ($owner, $member, $external, $group) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue(User::withTrashed()->find($owner->id)->trashed());
            $this->assertNotNull($member->fresh()->cancellation_requested_at);
            $this->assertNotNull($external->fresh()->cancellation_requested_at);
            $this->assertSame('closed', $group->fresh()->status);
            throw new RuntimeException('PRIVATE_PROVIDER_SECRET');
        });
        $this->actingAs($this->admin())->delete('/admin/users/'.$owner->id)
            ->assertRedirect()->assertSessionHas('error', 'Compte supprimé ; des annulations restent en attente et seront réessayées.');
        $this->assertSoftDeleted('users', ['id' => $owner->id]);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('left', $external->fresh()->status);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->gateway->shouldReceive('cancelSubscription')->twice();
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame('canceled', $external->fresh()->subscription_status);
        $this->assertSame('closed', $group->fresh()->status);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->delete('/admin/users/'.$admin->id)->assertSessionHas('error');
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_dry_run_has_no_writes_or_provider_calls(): void
    {
        [$payment, $member] = $this->fixture();
        $payment->update(['stripe_invoice_id' => 'in_recovery', 'credentials_check_due_at' => now()->addHours(48)]);
        $member->update(['cancellation_requested_at' => now(), 'subscription_status' => null]);
        $this->attempt($payment, 'requested');
        $before = [$member->fresh()->toArray(), $payment->fresh()->toArray(), (array) DB::table('payment_refund_attempts')->first()];
        $this->artisan('equitab:reconcile-payments', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame($before, [$member->fresh()->toArray(), $payment->fresh()->toArray(), (array) DB::table('payment_refund_attempts')->first()]);
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
    }

    public function test_command_uses_canonical_subscription_state_and_reports_errors_without_secrets(): void
    {
        [, $member] = $this->fixture();
        $member->update(['status' => 'pending_payment', 'stripe_customer_id' => 'cus_recovery']);
        $calls = 0;
        $this->reader->shouldReceive('retrieveSubscription')->twice()->with('sub_recovery')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('PRIVATE_PROVIDER_SECRET');
            }

            return ['id' => 'sub_recovery', 'customer' => 'cus_recovery', 'status' => 'canceled'];
        });
        $this->assertSame(1, Artisan::call('equitab:reconcile-payments'));
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_SECRET', Artisan::output());
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('left', $member->fresh()->status);
    }

    public function test_notification_failure_is_retried_without_another_refund_or_double_penalty(): void
    {
        [$payment, , $group] = $this->fixture();
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->gateway->shouldReceive('refundPayment')->once()->andReturn(['refund_id' => 're_recovery', 'status' => 'succeeded']);
        $mail = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('PRIVATE_MAIL_SECRET'));
        try {
            $this->job($payment);
            $this->fail('Failed delivery must be retryable.');
        } catch (BillingUnavailable $error) {
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertNull(DB::table('payment_refund_attempts')->value('notified_at'));
        $this->assertSame(0, $group->owner->fresh()->disputed_payments_count);
        Mail::swap($mail);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame(1, $group->owner->fresh()->disputed_payments_count);
        Mail::assertSent(AutoRefundProcessed::class, 1);
    }

    public function test_failed_refund_is_not_announced_and_dispute_cannot_be_rejected_during_refund(): void
    {
        [$payment, $member] = $this->fixture();
        $dispute = $this->dispute($payment);
        $this->attempt($payment, 'pending', 'dispute_resolved');
        $this->paidSubscriptionSnapshot($member);
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->reader->shouldReceive('retrieveRefund')->once()->andReturn([
            'id' => 're_recovery', 'payment_intent' => 'pi_recovery', 'status' => 'failed',
        ]);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(1);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('open', $dispute->fresh()->status);
        $this->actingAs($this->admin())->patch('/admin/disputes/'.$dispute->id.'/resolve', ['status' => 'resolved_rejected'])
            ->assertSessionHas('error');
        $this->assertSame('open', $dispute->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_invalid_job_target_or_existing_credentials_do_not_start_a_refund(): void
    {
        [$payment, , $group] = $this->fixture();
        (new CheckCredentialsProvided($payment->id, $group->id + 1, $payment->user_id))
            ->handle(app(BillingReconciliationService::class));
        $group->update(['credential_email' => 'synthetic@example.test']);
        $this->job($payment);
        $this->assertDatabaseCount('payment_refund_attempts', 0);
        Mail::assertNothingSent();
    }

    public function test_requested_refund_reuses_its_durable_key_after_a_lost_response(): void
    {
        [$payment] = $this->fixture();
        $keys = [];
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->gateway->shouldReceive('refundPayment')->twice()->andReturnUsing(function ($intent, $amount, $key) use (&$keys) {
            $this->assertSame('pi_recovery', $intent);
            $this->assertNull($amount);
            $this->assertTrue(Str::isUuid($key));
            $keys[] = $key;
            if (count($keys) === 1) {
                throw new RuntimeException('Response lost');
            }

            return ['refund_id' => 're_recovery', 'status' => 'succeeded'];
        });
        try {
            $this->job($payment);
            $this->fail('Lost response must remain retryable.');
        } catch (BillingUnavailable) {
            $this->assertSame('completed', $payment->fresh()->status);
        }
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertDatabaseCount('payment_refund_attempts', 1);
        $this->assertSame('refunded', $payment->fresh()->status);
        Mail::assertSent(AutoRefundProcessed::class, 1);
    }

    public function test_deletion_keeps_intentions_when_a_billing_lock_is_unavailable(): void
    {
        [, $member, $group] = $this->fixture();
        $lock = Cache::store('database')->lock('billing:group:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($this->admin())->delete('/admin/users/'.$group->owner_id)
                ->assertSessionHas('error', 'Compte supprimé ; des annulations restent en attente et seront réessayées.');
            $this->assertSoftDeleted('users', ['id' => $group->owner_id]);
            $this->assertNotNull($member->fresh()->cancellation_requested_at);
            $this->assertSame('closed', $group->fresh()->status);
        } finally {
            $lock->release();
        }
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('sub_recovery');
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
    }

    public function test_one_failed_subscription_does_not_prevent_an_independent_refund_recovery(): void
    {
        [$payment, $member] = $this->fixture();
        $this->attempt($payment);
        $member->update(['status' => 'pending_payment', 'stripe_customer_id' => 'cus_recovery']);
        $this->reader->shouldReceive('retrieveSubscription')->once()->andThrow(new RuntimeException('PRIVATE_PROVIDER_SECRET'));
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->reader->shouldReceive('retrieveRefund')->once()->andReturn([
            'id' => 're_recovery', 'payment_intent' => 'pi_recovery', 'status' => 'succeeded',
        ]);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(1);
        $this->assertSame('refunded', $payment->fresh()->status);
        Mail::assertSent(AutoRefundProcessed::class, 1);
    }

    #[DataProvider('liveMembershipStates')]
    public function test_missed_cancellation_webhook_releases_the_seat_once(string $localStatus): void
    {
        [$payment, $member, $group] = $this->fixture();
        $group->update(['status' => 'full', 'max_members' => 2]);
        $member->update(['status' => $localStatus]);
        $this->reader->shouldReceive('retrieveSubscription')->once()->with('sub_recovery')->andReturn([
            'id' => 'sub_recovery', 'customer' => 'cus_recovery', 'status' => 'canceled',
        ]);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame('open', $group->fresh()->status);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        Mail::assertNothingSent();
    }

    #[DataProvider('liveMembershipStates')]
    public function test_missed_paid_renewal_updates_entitlement_and_ledger_once(string $localStatus): void
    {
        [$payment, $member, $group] = $this->fixture();
        $payment->update(['stripe_invoice_id' => 'in_previous']);
        $member->update(['status' => $localStatus, 'current_period_end' => now()->subDay()]);
        $periodEnd = $this->paidSubscriptionSnapshot($member, 2, 'in_renewed', 'pi_renewed');
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame($periodEnd, $member->fresh()->current_period_end->timestamp);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['stripe_invoice_id' => 'in_renewed', 'stripe_payment_intent_id' => 'pi_renewed', 'status' => 'completed', 'amount' => 1999]);
        $this->assertSame(1, $payment->user->fresh()->completed_payments_count);
        Bus::assertDispatched(CheckCredentialsProvided::class, 1);
        Mail::assertNothingSent();
    }

    #[DataProvider('liveMembershipStates')]
    public function test_missed_failed_renewal_suspends_access_without_fabricating_a_receipt(string $localStatus): void
    {
        [, $member, $group] = $this->fixture();
        $member->update(['status' => $localStatus]);
        $this->reader->shouldReceive('retrieveSubscription')->twice()->with('sub_recovery')->andReturn([
            'id' => 'sub_recovery', 'customer' => 'cus_recovery', 'status' => 'active', 'currency' => 'cad', 'latest_invoice' => 'in_unpaid',
        ]);
        $this->reader->shouldReceive('retrieveInvoice')->twice()->with('in_unpaid')->andReturn([
            'id' => 'in_unpaid', 'customer' => 'cus_recovery', 'currency' => 'cad', 'status' => 'open',
            'amount_paid' => 0, 'amount_remaining' => 1999,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_recovery']],
        ]);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('suspended', $member->fresh()->status);
        $this->assertSame('past_due', $member->fresh()->subscription_status);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseCount('payments', 1);
        $this->actingAs($member->user)->getJson('/api/groups/'.$group->id.'/credentials')->assertForbidden();
        Bus::assertNotDispatched(CheckCredentialsProvided::class);
        Mail::assertNothingSent();
    }

    public function test_live_membership_inventory_excludes_terminal_missing_and_cancellation_requested_subscriptions(): void
    {
        $group = Group::factory()->create(['max_members' => 10]);
        foreach (['pending_payment', 'active', 'suspended', 'left', 'kicked'] as $state) {
            GroupMember::factory()->for($group)->withStripeSubscription('sub_'.$state)->create(['status' => $state]);
        }
        GroupMember::factory()->for($group)->create(['status' => 'active', 'stripe_subscription_id' => null]);
        GroupMember::factory()->for($group)->withStripeSubscription('sub_cancellation')->create(['status' => 'active', 'cancellation_requested_at' => now()]);
        $result = app(BillingReconciliationService::class)->reconcile(true);
        $this->assertSame(['cancellations' => 1, 'subscriptions' => 3, 'refunds' => 0, 'checks' => 0, 'errors' => 0], $result);
        Mail::assertNothingSent();
    }

    public function test_failed_credentials_check_dispatch_keeps_due_date_and_retries_once(): void
    {
        $this->travelTo(now()->startOfSecond());
        $due = now()->addHours(48);
        $payment = Payment::factory()->create(['stripe_invoice_id' => 'in_due', 'credentials_check_due_at' => $due]);
        $bus = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use ($payment, $due) {
            $this->assertInstanceOf(CheckCredentialsProvided::class, $job);
            $this->assertTrue($job->delay->equalTo($due));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull($payment->fresh()->credentials_check_queued_at);
            throw new RuntimeException('PRIVATE_QUEUE_SECRET');
        });
        $this->assertSame(1, Artisan::call('equitab:reconcile-payments'));
        $this->assertStringNotContainsString('PRIVATE_QUEUE_SECRET', Artisan::output());
        $this->assertNull($payment->fresh()->credentials_check_queued_at);
        $this->assertTrue($payment->fresh()->credentials_check_due_at->equalTo($due));
        $logs = Log::channel('billing_recovery')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertStringNotContainsString('PRIVATE_QUEUE_SECRET', json_encode($logs));
        Bus::swap($bus);
        $this->travel(30)->minutes();
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        Bus::assertDispatched(CheckCredentialsProvided::class, 1);
        Bus::assertDispatched(CheckCredentialsProvided::class, fn ($job) => $job->delay->equalTo($due));
        $this->assertNotNull($payment->fresh()->credentials_check_queued_at);
        $this->assertTrue($payment->fresh()->credentials_check_due_at->equalTo($due));
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_refund_attempts', 0);
        Mail::assertNothingSent();
    }

    public function test_committed_payment_survives_queue_outage_and_existing_payment_is_enqueued_on_reconciliation(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$previous, $member, $group] = $this->fixture();
        $previous->update(['stripe_invoice_id' => 'in_previous']);
        $this->paidSubscriptionSnapshot($member, 2, 'in_queue_recovery', 'pi_queue_recovery');
        $due = now()->addHours(48);
        $bus = Bus::getFacadeRoot();
        // Both canonical synchronization and the final recovery pass meet the
        // same unavailable transport; neither may stamp a successful enqueue.
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) use ($due) {
            $this->assertInstanceOf(CheckCredentialsProvided::class, $job);
            $this->assertTrue($job->delay->equalTo($due));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('payments', ['stripe_invoice_id' => 'in_queue_recovery', 'status' => 'completed', 'credentials_check_queued_at' => null]);
            throw new RuntimeException('PRIVATE_QUEUE_SECRET');
        });
        $this->artisan('equitab:reconcile-payments')->assertExitCode(1);
        $committed = Payment::where('stripe_invoice_id', 'in_queue_recovery')->sole();
        $this->assertTrue($committed->credentials_check_due_at->equalTo($due));
        $this->assertNull($committed->credentials_check_queued_at);
        Bus::swap($bus);
        $this->travel(1)->hours();
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, $previous->user->fresh()->completed_payments_count);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertNotNull($committed->fresh()->credentials_check_queued_at);
        $this->assertNull($previous->fresh()->credentials_check_due_at);
        Bus::assertDispatched(CheckCredentialsProvided::class, 1);
        Bus::assertDispatched(CheckCredentialsProvided::class, fn ($job) => $job->delay->equalTo($due));
        Mail::assertNothingSent();
    }

    public function test_credentials_check_inventory_excludes_legacy_zero_unpaid_refunded_and_already_queued_payments(): void
    {
        $this->travelTo(now()->startOfSecond());
        $base = Payment::factory()->create();
        $excluded = [
            ['credentials_check_due_at' => null],
            ['stripe_invoice_id' => null],
            ['amount' => 0],
            ['status' => 'pending'],
            ['status' => 'failed'],
            ['status' => 'refunded'],
            ['credentials_check_queued_at' => now()],
        ];
        foreach ($excluded as $index => $attributes) {
            Payment::factory()->for($base->group)->for($base->user)->create([
                'stripe_invoice_id' => 'in_excluded_'.$index, 'credentials_check_due_at' => now()->addHours(48), ...$attributes,
            ]);
        }
        $dueDates = [now()->subDay(), now()->addHours(48)];
        foreach ($dueDates as $index => $due) {
            Payment::factory()->for($base->group)->for($base->user)->create([
                'stripe_invoice_id' => 'in_due_'.$index, 'credentials_check_due_at' => $due,
            ]);
        }
        $before = Payment::orderBy('id')->get()->toArray();
        $this->assertSame(['cancellations' => 0, 'subscriptions' => 0, 'refunds' => 0, 'checks' => 2, 'errors' => 0],
            app(BillingReconciliationService::class)->reconcile(true));
        $this->assertSame($before, Payment::orderBy('id')->get()->toArray());
        Bus::assertNothingDispatched();
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        Bus::assertDispatched(CheckCredentialsProvided::class, 2);
        foreach ($dueDates as $due) {
            Bus::assertDispatched(CheckCredentialsProvided::class, fn ($job) => $job->delay->equalTo($due));
        }
        $this->assertSame(3, Payment::whereNotNull('credentials_check_queued_at')->count());
        $this->assertSame(0, app(BillingReconciliationService::class)->reconcile(true)['checks']);
        $this->assertNull($base->fresh()->credentials_check_due_at);
        $this->assertDatabaseCount('payment_refund_attempts', 0);
    }

    public function test_credentials_checks_recover_across_chunks_without_skipping_after_stamping(): void
    {
        $base = Payment::factory()->create();
        for ($index = 0; $index < 101; $index++) {
            Payment::factory()->for($base->group)->for($base->user)->create([
                'stripe_invoice_id' => 'in_chunk_'.$index, 'credentials_check_due_at' => now()->addHours(48),
            ]);
        }
        $result = app(BillingReconciliationService::class)->reconcile();
        $this->assertSame(101, $result['checks']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(101, Payment::whereNotNull('credentials_check_queued_at')->count());
        $this->assertSame(0, app(BillingReconciliationService::class)->reconcile()['checks']);
        Bus::assertDispatched(CheckCredentialsProvided::class, 101);
    }

    public function test_canonical_invoice_backfill_does_not_schedule_retrospective_legacy_checks(): void
    {
        [$payment, $member] = $this->fixture();
        $this->assertNull($payment->stripe_invoice_id);
        $this->assertNull($payment->credentials_check_due_at);
        $this->paidSubscriptionSnapshot($member, 2);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->artisan('equitab:reconcile-payments')->assertExitCode(0);
        $this->assertSame('in_recovery', $payment->fresh()->stripe_invoice_id);
        $this->assertNull($payment->fresh()->credentials_check_due_at);
        $this->assertNull($payment->fresh()->credentials_check_queued_at);
        $this->assertDatabaseCount('payments', 1);
        Bus::assertNothingDispatched();
    }

    #[DataProvider('historicalDisputeDeletions')]
    public function test_admin_can_read_and_refund_historical_disputes_after_soft_deletion(bool $deleteUser, bool $deleteGroup): void
    {
        [$payment, , $group] = $this->fixture();
        $group->update(['credential_password' => 'synthetic-service-secret']);
        $payer = $payment->user;
        $dispute = $this->dispute($payment);
        $serviceName = $group->subscription->name;
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('sub_recovery');
        $this->actingAs($this->admin());
        if ($deleteUser) {
            $this->delete('/admin/users/'.$payer->id)->assertSessionHas('success');
            $this->assertSoftDeleted($payer);
            $this->assertNull($dispute->fresh()->user);
        }
        if ($deleteGroup) {
            $group->delete();
            $this->assertSoftDeleted($group);
            $this->assertNull($dispute->fresh()->group);
        }
        $response = $this->get('/admin/disputes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Disputes')
                ->where('disputes.total', 1)->has('disputes.data', 1)
                ->where('disputes.data.0.id', $dispute->id)
                ->where('disputes.data.0.userName', $deleteUser ? 'Utilisateur supprimé' : $payer->name)
                ->where('disputes.data.0.userEmail', $deleteUser ? '—' : $payer->email)
                ->where('disputes.data.0.groupName', $group->name)
                ->where('disputes.data.0.subscriptionName', $serviceName)
                ->where('disputes.data.0.amount', $payment->amount)
                ->where('disputes.data.0.status', 'open'))
            ->assertDontSee('synthetic-service-secret');
        if ($deleteUser) {
            $response->assertDontSee($payer->email);
        }
        $this->gateway->shouldReceive('refundPayment')->once()->andReturn(['refund_id' => 're_history', 'status' => 'succeeded']);
        $this->patch('/admin/disputes/'.$dispute->id.'/resolve', ['status' => 'resolved_refund'])->assertSessionHas('success');
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('resolved_refund', $dispute->fresh()->status);
        $this->get('/admin/disputes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('disputes.data.0.status', 'resolved_refund'));
        Mail::assertSent(AutoRefundProcessed::class, 1);
        // Historical reads must not revive soft-deleted global relations.
        if ($deleteUser) {
            $this->assertNull($dispute->fresh()->user);
        }
        if ($deleteGroup) {
            $this->assertNull($dispute->fresh()->group);
        }
    }

    public static function historicalDisputeDeletions(): array
    {
        return [[true, false], [false, true], [true, true]];
    }

    public static function liveMembershipStates(): array
    {
        return [['active'], ['suspended']];
    }
}
