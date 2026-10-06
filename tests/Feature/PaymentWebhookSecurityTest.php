<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Jobs\CheckCredentialsProvided;
use App\Mail\IdentityVerified;
use App\Mail\PaymentFailed;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\ApiVersion;
use Tests\TestCase;

class PaymentWebhookSecurityTest extends TestCase
{
    protected MockInterface $billing;

    protected MockInterface $identity;

    protected MockInterface $payments;

    private TestHandler $logs;

    private array $originalEnvironment = [];

    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('w', 32)),
            'APP_CONFIG_CACHE' => '/private/tmp/equitab-webhook-tests-no-config.php',
            'APP_ROUTES_CACHE' => '/private/tmp/equitab-webhook-tests-no-routes.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
            'STRIPE_SECRET' => 'sk_test_webhook_no_network',
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
            'services.stripe.webhook_secret' => 'whsec_webhook_synthetic',
            'services.stripe.connect_webhook_secret' => 'whsec_connect_synthetic',
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.lock_table' => 'cache_locks',
            'logging.default' => 'webhook_security',
            'logging.channels.webhook_security' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Http::preventStrayRequests();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        $original = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new RuntimeException('Stripe network forbidden in webhook tests.'));
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
        $this->billing = Mockery::mock(BillingReadGatewayInterface::class);
        $this->identity = Mockery::mock(OwnerStripeGatewayInterface::class);
        $this->instance(BillingReadGatewayInterface::class, $this->billing);
        $this->instance(OwnerStripeGatewayInterface::class, $this->identity);
        $this->payments = Mockery::mock(PaymentGatewayInterface::class);
        $this->instance(PaymentGatewayInterface::class, $this->payments);
        $this->logs = Log::channel('webhook_security')->getLogger()->getHandlers()[0];
        // No wrapping transaction: assertions can prove remote reads and mail
        // happen outside SQL transactions, using a private in-memory database.
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

    protected function webhook(string $id, string $type, array $object, string $secret = 'whsec_webhook_synthetic', ?int $timestamp = null): TestResponse
    {
        $payload = json_encode([
            'id' => $id, 'object' => 'event', 'type' => $type,
            'api_version' => ApiVersion::CURRENT,
            'created' => time(), 'livemode' => false, 'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "$timestamp.$payload", $secret);

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t=$timestamp,v1=$signature",
        ], $payload);
    }

    private function identityObject(string $id = 'vs_current', array $extra = []): array
    {
        return ['id' => $id, 'object' => 'identity.verification_session', ...$extra];
    }

    public function test_invalid_signature_and_expired_signature_cannot_write_receipts_or_change_identity(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->webhook('evt_forged', 'identity.verification_session.verified', $this->identityObject(), 'invalid')
            ->assertBadRequest();
        $this->webhook('evt_expired', 'identity.verification_session.verified', $this->identityObject(), timestamp: time() - 600)
            ->assertBadRequest();
        $this->assertSame('unverified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    public function test_both_signing_secrets_are_accepted_without_relying_on_account_header(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->twice()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        foreach (['whsec_webhook_synthetic', 'whsec_connect_synthetic'] as $index => $secret) {
            $this->webhook('evt_secret_'.$index, 'identity.verification_session.verified', $this->identityObject(), $secret)->assertOk();
        }
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 2);
        Mail::assertSent(IdentityVerified::class, 1);
    }

    public function test_duplicate_receipt_prevents_second_provider_read_and_notification(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $this->webhook('evt_duplicate', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $verifiedAt = $owner->fresh()->identity_verified_at;
        $this->webhook('evt_duplicate', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertTrue($verifiedAt->equalTo($owner->fresh()->identity_verified_at));
        $this->assertDatabaseCount('stripe_events', 1);
        Mail::assertSent(IdentityVerified::class, 1);
    }

    public function test_failed_processing_returns_retryable_status_and_same_event_succeeds_on_retry(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->ordered()
            ->andThrow(new RuntimeException('SENSITIVE_PROVIDER_DETAIL'));
        $this->webhook('evt_retry', 'identity.verification_session.verified', $this->identityObject())
            ->assertStatus(503)->assertDontSee('SENSITIVE_PROVIDER_DETAIL');
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('unverified', $owner->fresh()->identity_status);
        $this->identity->shouldReceive('retrieveIdentity')->once()->ordered()
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $this->webhook('evt_retry', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertStringNotContainsString('SENSITIVE_PROVIDER_DETAIL', json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR));
    }

    public function test_out_of_order_identity_events_use_current_state_instead_of_event_status(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->twice()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $this->webhook('evt_verified', 'identity.verification_session.verified', $this->identityObject(extra: ['status' => 'verified']))->assertOk();
        $this->webhook('evt_old_processing', 'identity.verification_session.processing', $this->identityObject(extra: ['status' => 'processing']))->assertOk();
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertNotNull($owner->fresh()->identity_verified_at);
        Mail::assertSent(IdentityVerified::class, 1);
    }

    #[DataProvider('identityStates')]
    public function test_all_supported_identity_events_project_authoritative_state(string $eventState, string $providerState, string $expected): void
    {
        $owner = User::factory()->create([
            'stripe_identity_session_id' => 'vs_current', 'identity_status' => 'verified', 'identity_verified_at' => now(),
        ]);
        $this->identity->shouldReceive('retrieveIdentity')->once()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', $providerState));
        $this->webhook('evt_projection', 'identity.verification_session.'.$eventState, $this->identityObject())->assertOk();
        $this->assertSame($expected, $owner->fresh()->identity_status);
        $this->assertSame($expected === 'verified', $owner->fresh()->identity_verified_at !== null);
        Mail::assertNothingSent();
    }

    public static function identityStates(): array
    {
        return [
            ['processing', 'processing', 'pending'], ['requires_input', 'requires_input', 'unverified'],
            ['canceled', 'canceled', 'unverified'], ['verified', 'requires_input', 'unverified'],
        ];
    }

    public function test_old_session_and_metadata_cannot_verify_another_user(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $victim = User::factory()->create(['stripe_identity_session_id' => 'vs_victim']);
        $this->webhook('evt_old_session', 'identity.verification_session.verified', $this->identityObject('vs_old', [
            'metadata' => ['user_id' => (string) $owner->id],
        ]))->assertOk();
        $this->identity->shouldReceive('retrieveIdentity')->once()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $this->webhook('evt_foreign_metadata', 'identity.verification_session.verified', $this->identityObject(extra: [
            'metadata' => ['user_id' => (string) $victim->id],
        ]))->assertOk();
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertSame('unverified', $victim->fresh()->identity_status);
        Mail::assertSent(IdentityVerified::class, fn ($mail) => $mail->hasTo($owner->email));
        Mail::assertSent(IdentityVerified::class, 1);
    }

    public function test_identity_reader_checks_matching_session_and_preserves_state_on_mismatch(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()
            ->andReturn(new OwnerIdentityState('vs_different', 'verified'));
        $this->webhook('evt_mismatch', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);
        $this->assertSame('unverified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    public function test_owner_lock_contention_returns_retryable_status_without_provider_read(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $lease = Cache::store('database')->lock('stripe-owner:'.$owner->id, 300);
        $this->assertTrue($lease->get());
        Cache::forgetDriver('database');
        try {
            $this->webhook('evt_owner_busy', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);
            $this->assertDatabaseCount('stripe_events', 0);
        } finally {
            $lease->release();
        }
    }

    public function test_event_lock_is_shared_across_cache_instances_and_receipt_is_not_written_early(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('stripe_events', 0);
            Cache::forgetDriver('database');
            // Reentrant delivery models another worker while the first owns
            // the shared lease, not a process-local array-cache mutex.
            $this->webhook('evt_race', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);

            return new OwnerIdentityState('vs_current', 'verified');
        });
        $this->webhook('evt_race', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 1);
        Mail::assertSent(IdentityVerified::class, 1);
    }

    public function test_lost_owner_lease_cannot_write_stale_identity_state(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->andReturnUsing(function () use ($owner) {
            Cache::store('database')->lock('stripe-owner:'.$owner->id)->forceRelease();
            $owner->update(['stripe_identity_session_id' => 'vs_replaced', 'identity_status' => 'pending']);

            return new OwnerIdentityState('vs_current', 'verified');
        });
        $this->webhook('evt_lease_lost', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);
        $this->assertSame('pending', $owner->fresh()->identity_status);
        $this->assertSame('vs_replaced', $owner->fresh()->stripe_identity_session_id);
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    public function test_lost_event_lease_cannot_claim_a_completed_receipt_and_retry_is_idempotent(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->ordered()->andReturnUsing(function () {
            Cache::store('database')->lock('stripe-event:evt_lease_lost')->forceRelease();

            return new OwnerIdentityState('vs_current', 'verified');
        });
        $this->webhook('evt_lease_lost', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->identity->shouldReceive('retrieveIdentity')->once()->ordered()
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $this->webhook('evt_lease_lost', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertDatabaseCount('stripe_events', 1);
        Mail::assertNothingSent(); // Best effort, never duplicate after a partial commit.
    }

    public function test_identity_mail_is_best_effort_after_commit_and_both_locks_release(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $pending = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('to')->once()->with($owner->email)->andReturn($pending);
        $observed = false;
        $pending->shouldReceive('send')->once()->andReturnUsing(function (IdentityVerified $mail) use ($owner, &$observed) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue(StripeEvent::where('stripe_event_id', 'evt_mail')->whereNotNull('processed_at')->exists());
            foreach (['stripe-owner:'.$owner->id, 'stripe-event:evt_mail'] as $key) {
                $lease = Cache::store('database')->lock($key, 10);
                $this->assertTrue($lease->get());
                $lease->release();
            }
            $this->assertSame($owner->id, $mail->user->id);
            $observed = true;
            throw new RuntimeException('PRIVATE_MAIL_DETAIL');
        });
        $this->webhook('evt_mail', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertTrue($observed);
        $this->webhook('evt_mail', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertStringNotContainsString('PRIVATE_MAIL_DETAIL', json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR));
    }

    public function test_receipt_storage_failure_is_retryable_after_idempotent_identity_commit(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->twice()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $failOnce = true;
        Event::listen('eloquent.creating: '.StripeEvent::class, function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('Synthetic receipt storage failure.');
            }
        });

        $this->webhook('evt_storage_retry', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->webhook('evt_storage_retry', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        Mail::assertNothingSent();
    }

    public function test_unprocessed_receipt_is_retried_and_replaced_with_minimal_metadata(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        StripeEvent::create([
            'stripe_event_id' => 'evt_incomplete', 'type' => 'identity.verification_session.verified',
            'payload' => ['old' => 'LEGACY_SECRET_SENTINEL'], 'processed_at' => null,
        ]);
        $this->identity->shouldReceive('retrieveIdentity')->once()->with('vs_current')
            ->andReturn(new OwnerIdentityState('vs_current', 'verified'));
        $this->webhook('evt_incomplete', 'identity.verification_session.verified', $this->identityObject())->assertOk();
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 1);
        $receipt = StripeEvent::firstOrFail();
        $this->assertNotNull($receipt->processed_at);
        $this->assertSame(['object_id' => 'vs_current', 'object_type' => 'identity.verification_session'], $receipt->payload);
        Mail::assertSent(IdentityVerified::class, 1);
    }

    public function test_receipts_and_logs_never_persist_provider_secrets_or_personal_payloads(): void
    {
        User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->andReturn(new OwnerIdentityState('vs_current', 'processing'));
        $this->webhook('evt_minimal', 'identity.verification_session.processing', $this->identityObject(extra: [
            'client_secret' => 'CLIENT_SECRET_SENTINEL', 'metadata' => ['email' => 'PRIVATE_EMAIL_SENTINEL'],
            'verified_outputs' => ['first_name' => 'PRIVATE_NAME_SENTINEL'],
        ]))->assertOk();
        $this->assertSame(['object_id' => 'vs_current', 'object_type' => 'identity.verification_session'], StripeEvent::firstOrFail()->payload);
        $surfaces = StripeEvent::all()->toJson().json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR);
        foreach (['CLIENT_SECRET_SENTINEL', 'PRIVATE_EMAIL_SENTINEL', 'PRIVATE_NAME_SENTINEL'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $surfaces);
        }
    }

    public function test_unknown_event_is_acknowledged_without_persisting_raw_payload(): void
    {
        $this->webhook('evt_unknown', 'future.event.type', ['id' => 'arbitrary', 'client_secret' => 'PRIVATE_SENTINEL'])->assertOk();
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    #[DataProvider('invalidObjects')]
    public function test_known_events_with_missing_or_invalid_schema_are_retryable_not_successful(string $type, array $object): void
    {
        $this->webhook('evt_invalid_schema', $type, $object)->assertStatus(503);
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public static function invalidObjects(): array
    {
        return [
            ['invoice.paid', ['id' => 'in_test', 'object' => 'invoice']],
            ['invoice.payment_failed', ['id' => 'in_test', 'object' => 'invoice', 'parent' => ['type' => 'unknown']]],
            ['invoice.paid', ['id' => 'in_test', 'object' => 'invoice', 'parent' => ['type' => 'subscription_details', 'subscription_details' => []]]],
            ['invoice.paid', ['id' => 'in_test', 'object' => 'invoice', 'subscription' => ['id' => '../../unexpected']]],
            ['invoice.paid', ['id' => 'in_test', 'object' => 'invoice', 'parent' => null, 'subscription' => 'sub_conflicting']],
            ['invoice.paid', ['id' => 'in_test', 'object' => 'invoice', 'subscription' => 'sub_conflicting', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_other']]]],
            ['invoice.paid', ['id' => 'in_test', 'object' => 'charge', 'subscription' => 'sub_test']],
            ['invoice_payment.paid', ['id' => 'inpay_test', 'object' => 'invoice_payment']],
            ['refund.updated', ['id' => 're_test', 'object' => 'refund']],
            ['identity.verification_session.verified', ['object' => 'identity.verification_session']],
            ['customer.subscription.deleted', ['id' => 'sub_test']],
        ];
    }

    public function test_explicit_standalone_invoice_is_not_confused_with_unknown_invoice_schema(): void
    {
        $this->webhook('evt_standalone', 'invoice.paid', ['id' => 'in_standalone', 'object' => 'invoice', 'parent' => null])->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stripe_events', 1);
    }

    protected function subscribedMember(string $subscriptionId = 'sub_current', string $status = 'active'): GroupMember
    {
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_current']);

        return GroupMember::factory()->create([
            'group_id' => Group::factory()->withCredentials()->create(['current_members' => 2])->id,
            'user_id' => $payer->id, 'status' => $status, 'subscription_status' => 'active',
            'stripe_subscription_id' => $subscriptionId, 'stripe_customer_id' => 'cus_current',
            'share_amount' => 1000, 'current_period_end' => now()->addMonth(),
        ]);
    }

    protected function subscriptionSnapshot(GroupMember $member, string $status = 'canceled'): array
    {
        return [
            'id' => $member->stripe_subscription_id, 'object' => 'subscription', 'status' => $status,
            'customer' => $member->stripe_customer_id, 'latest_invoice' => 'in_current',
            'current_period_start' => now()->subDay()->timestamp, 'current_period_end' => now()->addMonth()->timestamp,
            'items' => ['data' => [[
                'id' => 'si_current', 'current_period_start' => now()->subDay()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
            ]]],
        ];
    }

    public function test_current_invoice_parent_schema_suspends_member_after_failed_renewal(): void
    {
        $member = $this->subscribedMember();
        $this->billing->shouldReceive('retrieveSubscription')->once()->with('sub_current')
            ->andReturn($this->subscriptionSnapshot($member, 'past_due'));
        $this->webhook('evt_current_schema', 'invoice.payment_failed', [
            'id' => 'in_current', 'object' => 'invoice',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_current']],
        ])->assertOk();
        $this->assertSame('suspended', $member->fresh()->status);
        $this->assertSame('past_due', $member->fresh()->subscription_status);
        $this->actingAs($member->user)->getJson('/api/groups/'.$member->group_id.'/credentials')->assertForbidden();
        $this->assertDatabaseCount('stripe_events', 1);
    }

    public function test_invoice_payment_resolves_invoice_and_canceled_subscription_cannot_reactivate_member(): void
    {
        $member = $this->subscribedMember(status: 'left');
        $this->billing->shouldReceive('retrieveInvoice')->once()->with('in_current')->andReturn([
            'id' => 'in_current', 'object' => 'invoice', 'status' => 'paid',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => ['id' => 'sub_current']]],
        ]);
        $this->billing->shouldReceive('retrieveSubscription')->once()->with('sub_current')
            ->andReturn($this->subscriptionSnapshot($member));
        $this->webhook('evt_invoice_payment', 'invoice_payment.paid', [
            'id' => 'inpay_current', 'object' => 'invoice_payment', 'invoice' => ['id' => 'in_current'],
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_current'],
        ])->assertOk();
        $this->assertSame('left', $member->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_legacy_invoice_reference_is_supported_but_old_paid_event_cannot_override_cancellation(): void
    {
        $member = $this->subscribedMember();
        $this->billing->shouldReceive('retrieveSubscription')->once()->with('sub_current')
            ->andReturn($this->subscriptionSnapshot($member));
        $this->webhook('evt_legacy', 'invoice.paid', [
            'id' => 'in_old', 'object' => 'invoice', 'subscription' => 'sub_current', 'status' => 'paid',
        ])->assertOk();
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invoice_payment_with_mismatched_canonical_invoice_cannot_write_receipt(): void
    {
        $this->billing->shouldReceive('retrieveInvoice')->once()->with('in_expected')->andReturn([
            'id' => 'in_other', 'object' => 'invoice', 'subscription' => 'sub_current',
        ]);
        $this->webhook('evt_wrong_invoice', 'invoice_payment.paid', [
            'id' => 'inpay_current', 'object' => 'invoice_payment', 'invoice' => 'in_expected',
        ])->assertStatus(503);
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_unknown_identity_provider_status_is_not_written_or_acknowledged_as_processed(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()
            ->andReturn(new OwnerIdentityState('vs_current', 'unexpected_future_state'));
        $this->webhook('evt_unknown_identity_state', 'identity.verification_session.processing', $this->identityObject())->assertStatus(503);
        $this->assertSame('unverified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    public function test_session_replaced_during_provider_read_cannot_be_overwritten_even_if_lease_is_owned(): void
    {
        $owner = User::factory()->create(['stripe_identity_session_id' => 'vs_current']);
        $this->identity->shouldReceive('retrieveIdentity')->once()->andReturnUsing(function () use ($owner) {
            $owner->update(['stripe_identity_session_id' => 'vs_new', 'identity_status' => 'pending']);

            return new OwnerIdentityState('vs_current', 'verified');
        });
        $this->webhook('evt_session_changed', 'identity.verification_session.verified', $this->identityObject())->assertStatus(503);
        $this->assertSame('pending', $owner->fresh()->identity_status);
        $this->assertSame('vs_new', $owner->fresh()->stripe_identity_session_id);
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    public function test_refund_notification_reconciles_current_state_and_revokes_access(): void
    {
        $member = $this->subscribedMember();
        $payment = Payment::factory()->create([
            'group_id' => $member->group_id, 'user_id' => $member->user_id,
            'amount' => 1000, 'stripe_payment_intent_id' => 'pi_current',
        ]);
        $this->billing->shouldReceive('retrievePaymentIntent')->once()->with('pi_current')->andReturn([
            'id' => 'pi_current', 'object' => 'payment_intent', 'customer' => 'cus_current',
            'status' => 'succeeded', 'currency' => 'cad', 'amount_received' => 1000,
            'latest_charge' => ['id' => 'ch_current', 'refunded' => true, 'amount_refunded' => 1000],
        ]);
        $this->payments->shouldReceive('cancelSubscription')->once()->with('sub_current');
        $this->webhook('evt_refund', 'refund.updated', [
            'id' => 're_current', 'object' => 'refund', 'payment_intent' => ['id' => 'pi_current'],
            'status' => 'pending', // An old notification must not override the current refund.
        ])->assertOk();

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $member->group->fresh()->current_members);
        $this->actingAs($member->user)->getJson('/api/groups/'.$member->group_id.'/credentials')->assertForbidden();
        $this->assertDatabaseCount('stripe_events', 1);
    }

    public function test_refund_event_does_not_claim_success_when_current_charge_has_not_been_refunded(): void
    {
        $member = $this->subscribedMember();
        $payment = Payment::factory()->create([
            'group_id' => $member->group_id, 'user_id' => $member->user_id,
            'amount' => 1000, 'stripe_payment_intent_id' => 'pi_current',
        ]);
        $this->billing->shouldReceive('retrievePaymentIntent')->once()->with('pi_current')->andReturn([
            'id' => 'pi_current', 'object' => 'payment_intent', 'customer' => 'cus_current',
            'status' => 'succeeded', 'currency' => 'cad', 'amount_received' => 1000,
            'latest_charge' => ['id' => 'ch_current', 'refunded' => false, 'amount_refunded' => 0],
        ]);
        $this->webhook('evt_refund_failed', 'refund.failed', [
            'id' => 're_current', 'object' => 'refund', 'payment_intent' => 'pi_current', 'status' => 'failed',
        ])->assertOk();

        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->refunded_at);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertDatabaseCount('stripe_events', 1);
    }

    #[DataProvider('paidInvoiceNotifications')]
    public function test_paid_invoice_and_invoice_payment_create_one_payment_and_one_activation(string $notification): void
    {
        $member = $this->subscribedMember(status: 'pending_payment');
        $invoice = [
            'id' => 'in_current', 'object' => 'invoice', 'status' => 'paid', 'customer' => 'cus_current',
            'currency' => 'cad', 'amount_paid' => 1000, 'amount_due' => 1000, 'amount_remaining' => 0,
            'period_start' => now()->subDay()->timestamp, 'period_end' => now()->addMonth()->timestamp,
            'status_transitions' => ['paid_at' => now()->timestamp],
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_current']],
            'client_secret' => 'PRIVATE_PAYMENT_SECRET',
        ];
        $subscription = [...$this->subscriptionSnapshot($member, 'active'), 'currency' => 'cad'];
        $this->billing->shouldReceive('retrieveSubscription')->twice()->with('sub_current')->andReturnUsing(function () use ($subscription) {
            $this->assertSame(0, DB::transactionLevel());

            return $subscription;
        });
        $this->billing->shouldReceive('retrieveInvoice')->times(3)->with('in_current')->andReturn($invoice);
        $this->billing->shouldReceive('invoicePayments')->twice()->with('in_current')->andReturn([[
            'id' => 'inpay_current', 'object' => 'invoice_payment', 'invoice' => 'in_current',
            'status' => 'paid', 'amount_paid' => 1000,
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_current'],
        ]]);
        $this->billing->shouldReceive('retrievePaymentIntent')->twice()->with('pi_current')->andReturn([
            'id' => 'pi_current', 'object' => 'payment_intent', 'customer' => 'cus_current',
            'status' => 'succeeded', 'currency' => 'cad', 'amount_received' => 1000,
            'latest_charge' => ['id' => 'ch_current', 'refunded' => false, 'amount_refunded' => 0],
        ]);

        $this->webhook('evt_invoice_paid', $notification, $invoice)->assertOk();
        $this->webhook('evt_invoice_paid', $notification, $invoice)->assertOk();
        $this->webhook('evt_invoice_payment_paid', 'invoice_payment.paid', [
            'id' => 'inpay_current', 'object' => 'invoice_payment', 'invoice' => 'in_current',
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_current'],
        ])->assertOk();

        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame(1, $member->user->fresh()->completed_payments_count);
        $this->assertSame(2, $member->group->fresh()->current_members);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['stripe_invoice_id' => 'in_current', 'stripe_payment_intent_id' => 'pi_current', 'status' => 'completed']);
        $this->assertDatabaseCount('stripe_events', 2);
        Bus::assertDispatched(CheckCredentialsProvided::class, 1);
        Mail::assertNotSent(PaymentFailed::class);
        $this->assertStringNotContainsString('PRIVATE_PAYMENT_SECRET', StripeEvent::all()->toJson());
    }

    public static function paidInvoiceNotifications(): array
    {
        return [['invoice.paid'], ['invoice.payment_succeeded'], ['invoice.payment_failed']];
    }
}
