<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Controllers\PaymentController;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Features\Payment\Services\OwnerConnectAccountService;
use App\Features\Payment\Services\OwnerOnboardingException;
use App\Features\Payment\Services\OwnerOnboardingService;
use App\Features\Payment\Services\StripeGateway;
use App\Mail\ConnectAccountActivated;
use App\Models\GroupDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class StripeOwnerOnboardingTest extends TestCase
{
    private OwnerStripeGatewayInterface $gateway;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config([
            'services.stripe.secret' => 'sk_test_owner_dummy_no_network',
            'services.stripe.webhook_secret' => 'whsec_owner_dummy',
            'services.stripe.connect_webhook_secret' => 'whsec_connect_dummy',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.lock_table' => 'cache_locks',
        ]);
        Http::preventStrayRequests();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new \LogicException('Network forbidden in owner tests.'));
        ApiRequestor::setHttpClient($transport);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeRefreshingDatabase();
        // Each test owns an in-memory database, without an outer transaction.
        // Disconnecting discards it; rollback migrations are not needed.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Mail::fake();
        $this->gateway = Mockery::mock(OwnerStripeGatewayInterface::class);
        $this->app->instance(OwnerStripeGatewayInterface::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function draft(User $user): GroupDraft
    {
        $draft = new GroupDraft;
        $draft->forceFill(['owner_id' => $user->id, 'data' => ['name' => 'Private draft']])->save();

        return $draft;
    }

    private function expectLink(User $user, ?GroupDraft $draft = null): void
    {
        $query = $draft ? ['draft_id' => $draft->id] : [];
        $this->gateway->shouldReceive('createAccountLink')->once()->with(
            $user->stripe_connect_account_id,
            route('stripe.onboarding.return', $query),
            route('stripe.onboarding.refresh', $query),
        )->andReturn('https://connect.stripe.com/setup/dummy');
    }

    public function test_existing_account_and_owned_draft_use_internal_callbacks(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_existing']);
        $draft = $this->draft($user);
        $this->expectLink($user, $draft);

        $this->actingAs($user)->postJson('/api/stripe/onboarding', ['draft_id' => $draft->id])
            ->assertOk()->assertExactJson(['url' => 'https://connect.stripe.com/setup/dummy']);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('owner_connect_attempts', 0);
    }

    public function test_new_account_creation_preserves_policy_and_only_prefills_confirmed_email(): void
    {
        $user = User::factory()->create(['name' => 'Do not split this name', 'email' => 'owner@example.test']);
        $this->gateway->shouldReceive('createAccount')->once()->withArgs(function (array $parameters, string $key): bool {
            $this->assertSame([
                'type' => 'express', 'country' => 'CA', 'email' => 'owner@example.test',
                'business_type' => 'individual',
                'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]],
            ], $parameters);
            $this->assertTrue(Str::isUuid($key));
            $this->assertDatabaseCount('owner_connect_attempts', 1);

            return true;
        })->andReturn('acct_created');
        $this->gateway->shouldReceive('createAccountLink')->once()->andReturn('https://connect.stripe.com/setup/dummy');

        $this->actingAs($user)->postJson('/api/stripe/onboarding')->assertOk();
        $this->assertSame('acct_created', $user->fresh()->stripe_connect_account_id);
        $attempt = DB::table('owner_connect_attempts')->first();
        $this->assertStringNotContainsString('owner@example.test', $attempt->parameters);
        $this->assertSame('owner@example.test', json_decode(Crypt::decryptString($attempt->parameters), true)['email']);
    }

    public function test_lost_response_retries_identical_durable_parameters_and_key(): void
    {
        $user = User::factory()->create(['email' => 'before@example.test']);
        $parameters = null;
        $key = null;
        $this->gateway->shouldReceive('createAccount')->once()->ordered()->withArgs(function ($params, $idempotency) use (&$parameters, &$key) {
            $parameters = $params;
            $key = $idempotency;

            return true;
        })->andThrow(new \RuntimeException('Lost response with sensitive detail'));
        $this->actingAs($user)->postJson('/api/stripe/onboarding')->assertStatus(503)
            ->assertDontSee('sensitive detail');
        $this->assertNull($user->fresh()->stripe_connect_account_id);
        $this->assertDatabaseCount('owner_connect_attempts', 1);

        $user->update(['email' => 'after@example.test']);
        $this->gateway->shouldReceive('createAccount')->once()->ordered()->with($parameters, $key)->andReturn('acct_recovered');
        $this->gateway->shouldReceive('createAccountLink')->once()->andReturn('https://connect.stripe.com/setup/recovered');
        $this->postJson('/api/stripe/onboarding')->assertOk();
        $this->assertSame('acct_recovered', $user->fresh()->stripe_connect_account_id);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
    }

    public function test_complete_real_address_is_prefilled_without_splitting_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Never parse a legal name', 'address' => '123 rue Exemple',
            'city' => 'Montréal', 'province' => 'qc', 'postal_code' => 'H2X 1Y4',
        ]);
        $this->gateway->shouldReceive('createAccount')->once()->withArgs(function ($params): bool {
            $this->assertSame(0, DB::transactionLevel(), 'Stripe must run outside SQL transactions.');
            $this->assertSame(['address' => [
                'line1' => '123 rue Exemple', 'city' => 'Montréal', 'state' => 'QC', 'postal_code' => 'H2X 1Y4', 'country' => 'CA',
            ]], $params['individual']);

            return true;
        })->andReturn('acct_address');
        $this->assertSame('acct_address', app(OwnerConnectAccountService::class)->accountId($user));
    }

    public function test_incomplete_address_is_left_for_owner_to_complete_at_stripe(): void
    {
        $user = User::factory()->create(['address' => '123 rue Exemple', 'province' => 'QC']);
        $this->gateway->shouldReceive('createAccount')->once()->withArgs(function ($params): bool {
            $this->assertArrayNotHasKey('individual', $params);

            return true;
        })->andReturn('acct_partial');
        $this->assertSame('acct_partial', app(OwnerConnectAccountService::class)->accountId($user));
    }

    public function test_competing_creation_is_rejected_while_owner_lock_is_held(): void
    {
        $user = User::factory()->create();
        $lease = Cache::store('database')->lock('stripe-owner:'.$user->id, 300);
        $this->assertTrue($lease->get());
        try {
            $this->actingAs($user)->postJson('/api/stripe/onboarding')->assertStatus(503);
            $this->assertDatabaseCount('owner_connect_attempts', 0);
        } finally {
            $lease->release();
        }
    }

    public function test_lost_lease_does_not_overwrite_an_account_created_by_another_worker(): void
    {
        $user = User::factory()->create();
        $this->gateway->shouldReceive('createAccount')->once()->andReturnUsing(function () use ($user) {
            $this->assertSame(0, DB::transactionLevel());
            $user->update(['stripe_connect_account_id' => 'acct_other_worker']);
            Cache::store('database')->lock('stripe-owner:'.$user->id)->forceRelease();

            return 'acct_late_response';
        });
        $this->actingAs($user)->postJson('/api/stripe/onboarding')->assertStatus(503);
        $this->assertSame('acct_other_worker', $user->fresh()->stripe_connect_account_id);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
    }

    public function test_expired_ambiguous_attempt_is_not_recreated(): void
    {
        $user = User::factory()->create();
        DB::table('owner_connect_attempts')->insert([
            'user_id' => $user->id, 'idempotency_key' => (string) Str::uuid(),
            'parameters' => Crypt::encryptString('{}'), 'started_at' => now()->subHours(23),
        ]);
        $this->actingAs($user)->postJson('/api/stripe/onboarding')->assertStatus(503);
        $this->assertNull($user->fresh()->stripe_connect_account_id);
    }

    public function test_stale_user_instance_reuses_account_created_by_another_request(): void
    {
        $user = User::factory()->create();
        $stale = $user->fresh();
        $user->update(['stripe_connect_account_id' => 'acct_other_request']);
        $this->assertSame('acct_other_request', app(OwnerConnectAccountService::class)->accountId($stale));
        $this->assertDatabaseCount('owner_connect_attempts', 0);
    }

    public function test_creation_inside_outer_transaction_fails_before_external_call(): void
    {
        $user = User::factory()->create();
        DB::beginTransaction();
        try {
            app(OwnerConnectAccountService::class)->accountId($user);
            $this->fail('An uncommitted journal cannot protect a lost response.');
        } catch (OwnerOnboardingException) {
            $this->assertDatabaseCount('owner_connect_attempts', 0);
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_or_foreign_draft_is_rejected_before_stripe(string $endpoint, string $kind): void
    {
        $user = User::factory()->create();
        $draftId = match ($kind) {
            'foreign' => $this->draft(User::factory()->create())->id,
            'missing' => (string) Str::uuid(),
            default => 'https://outside.example/draft',
        };
        $this->actingAs($user)->postJson($endpoint, ['draft_id' => $draftId])->assertUnprocessable();
        $this->assertDatabaseCount('owner_connect_attempts', 0);
    }

    public static function invalidDrafts(): array
    {
        $cases = [];
        foreach (['onboarding', 'identity'] as $action) {
            foreach (['foreign', 'missing', 'invalid'] as $kind) {
                $cases["$action $kind"] = ["/api/stripe/$action", $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('deniedOwners')]
    public function test_owner_access_is_enforced_for_api(string $endpoint, string $state): void
    {
        $attributes = $state === 'unverified' ? ['email_verified_at' => null] : ['status' => $state];
        $user = User::factory()->create($attributes);
        $this->actingAs($user)->postJson($endpoint)->assertForbidden();
    }

    public static function deniedOwners(): array
    {
        $cases = [];
        foreach (['onboarding', 'identity'] as $action) {
            foreach (['suspended', 'banned', 'unverified'] as $state) {
                $cases["$action $state"] = ["/api/stripe/$action", $state];
            }
        }

        return $cases;
    }

    public function test_guest_cannot_start_or_return_from_onboarding(): void
    {
        $this->postJson('/api/stripe/onboarding')->assertUnauthorized();
        $this->postJson('/api/stripe/identity')->assertUnauthorized();
        $this->get('/stripe/onboarding/return')->assertRedirect('/login');
        $this->get('/stripe/onboarding/refresh')->assertRedirect('/login');
    }

    public function test_return_reads_both_states_ignores_browser_status_and_never_publishes(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner', 'stripe_identity_session_id' => 'vs_owner']);
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('retrieveAccount')->once()->with('acct_owner')->andReturn(new OwnerConnectState('acct_owner', true, false, true));
        $this->gateway->shouldReceive('retrieveIdentity')->once()->with('vs_owner')->andReturn(new OwnerIdentityState('vs_owner', 'requires_input'));

        $this->actingAs($user)->get('/stripe/onboarding/return?'.http_build_query([
            'draft_id' => $draft->id, 'stripe_connect_status' => 'active', 'identity_status' => 'verified',
        ]))->assertRedirect(route('group-drafts.edit', ['draft' => $draft->id]));
        $this->assertSame('pending', $user->fresh()->stripe_connect_status);
        $this->assertSame('unverified', $user->fresh()->identity_status);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_return_failure_preserves_states_and_flashes_safe_error_to_draft(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner', 'stripe_identity_session_id' => 'vs_owner']);
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('retrieveAccount')->once()->andReturn(new OwnerConnectState('acct_owner', true, true, true));
        $this->gateway->shouldReceive('retrieveIdentity')->once()->andThrow(new \RuntimeException('private SDK error'));
        $this->actingAs($user)->get('/stripe/onboarding/return?draft_id='.$draft->id)
            ->assertRedirect(route('group-drafts.edit', ['draft' => $draft->id]))
            ->assertSessionHas('error', (new OwnerOnboardingException)->getMessage());
        $this->assertSame('not_started', $user->fresh()->stripe_connect_status);
        $this->assertSame('unverified', $user->fresh()->identity_status);
    }

    public function test_return_to_foreign_draft_never_reads_stripe(): void
    {
        $user = User::factory()->create();
        $draft = $this->draft(User::factory()->create());
        $this->actingAs($user)->getJson('/stripe/onboarding/return?draft_id='.$draft->id)->assertUnprocessable();
        $this->getJson('/stripe/onboarding/refresh?draft_id='.$draft->id)->assertUnprocessable();
    }

    public function test_expired_link_regenerates_for_existing_account_and_draft(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_existing']);
        $draft = $this->draft($user);
        $this->expectLink($user, $draft);
        $this->actingAs($user)->get('/stripe/onboarding/refresh?draft_id='.$draft->id)
            ->assertRedirect('https://connect.stripe.com/setup/dummy');
    }

    public function test_link_failure_keeps_existing_account_and_returns_to_draft(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_existing']);
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('createAccountLink')->once()->andThrow(new \RuntimeException('private link error'));
        $this->actingAs($user)->get('/stripe/onboarding/refresh?draft_id='.$draft->id)
            ->assertRedirect(route('group-drafts.edit', ['draft' => $draft->id]))
            ->assertSessionHas('error', (new OwnerOnboardingException)->getMessage());
        $this->assertSame('acct_existing', $user->fresh()->stripe_connect_account_id);
    }

    public function test_return_without_a_draft_or_account_never_invents_success(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/stripe/onboarding/return?status=active&return_url=https://outside.example')
            ->assertRedirect('/dashboard/profile')->assertSessionMissing('success');
        $this->assertSame('not_started', $user->fresh()->stripe_connect_status);
        $this->assertSame('unverified', $user->fresh()->identity_status);
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_refresh_rechecks_previously_active_account_and_verified_identity(): void
    {
        $user = User::factory()->create([
            'stripe_connect_account_id' => 'acct_owner', 'stripe_identity_session_id' => 'vs_owner',
            'stripe_connect_status' => 'active', 'identity_status' => 'verified', 'identity_verified_at' => now(),
        ]);
        $this->gateway->shouldReceive('retrieveAccount')->once()->andReturn(new OwnerConnectState('acct_owner', false, false, true, false, 'rejected.other'));
        $this->gateway->shouldReceive('retrieveIdentity')->once()->andReturn(new OwnerIdentityState('vs_owner', 'requires_input'));
        app(OwnerOnboardingService::class)->refresh($user);
        $this->assertSame('restricted', $user->stripe_connect_status);
        $this->assertSame('unverified', $user->identity_status);
        $this->assertNull($user->identity_verified_at);
    }

    public function test_refresh_can_confirm_both_states_without_publishing(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner', 'stripe_identity_session_id' => 'vs_owner']);
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('retrieveAccount')->once()->andReturn(new OwnerConnectState('acct_owner', true, true, true));
        $this->gateway->shouldReceive('retrieveIdentity')->once()->andReturn(new OwnerIdentityState('vs_owner', 'verified'));
        app(OwnerOnboardingService::class)->refresh($user);
        $this->assertSame('active', $user->stripe_connect_status);
        $this->assertSame('verified', $user->identity_status);
        $this->assertNotNull($user->identity_verified_at);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_legacy_verified_identity_without_session_id_is_preserved(): void
    {
        $verifiedAt = now()->subYear()->startOfSecond();
        $user = User::factory()->create(['identity_status' => 'verified', 'identity_verified_at' => $verifiedAt]);
        app(OwnerOnboardingService::class)->refresh($user);
        $this->assertSame('verified', $user->identity_status);
        $this->assertTrue($verifiedAt->equalTo($user->identity_verified_at));
        $this->assertSame('not_started', $user->stripe_connect_status);
        $this->actingAs($user)->postJson('/api/stripe/identity')->assertOk()
            ->assertExactJson(['url' => url('/dashboard/profile')]);
        $this->assertNull($user->fresh()->stripe_identity_session_id);
    }

    public function test_refresh_network_is_outside_transaction_and_a_lost_lease_cannot_write_stale_status(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->once()->andReturnUsing(function () use ($user) {
            $this->assertSame(0, DB::transactionLevel());
            Cache::store('database')->lock('stripe-owner:'.$user->id)->forceRelease();
            $user->update(['stripe_connect_status' => 'restricted']);

            return new OwnerConnectState('acct_owner', true, true, true);
        });
        try {
            app(OwnerOnboardingService::class)->refresh($user);
            $this->fail('A stale response must not be persisted.');
        } catch (OwnerOnboardingException) {
            $this->assertSame('restricted', $user->fresh()->stripe_connect_status);
        }
    }

    public function test_replaced_identity_session_wins_over_in_flight_status_response(): void
    {
        $user = User::factory()->create(['stripe_identity_session_id' => 'vs_old']);
        $this->gateway->shouldReceive('retrieveIdentity')->once()->with('vs_old')->andReturnUsing(function () use ($user) {
            $this->assertSame(0, DB::transactionLevel());
            $user->update(['stripe_identity_session_id' => 'vs_new']);

            return new OwnerIdentityState('vs_old', 'verified');
        });
        try {
            app(OwnerOnboardingService::class)->refresh($user);
            $this->fail('A replaced session cannot verify the current session.');
        } catch (OwnerOnboardingException) {
            $this->assertSame('unverified', $user->fresh()->identity_status);
            $this->assertSame('vs_new', $user->fresh()->stripe_identity_session_id);
        }
    }

    public function test_identity_reuses_session_and_remembers_authorized_draft_in_browser_session(): void
    {
        $user = User::factory()->create(['stripe_identity_session_id' => 'vs_existing']);
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('retrieveIdentity')->once()->with('vs_existing')
            ->andReturn(new OwnerIdentityState('vs_existing', 'requires_input', 'https://verify.stripe.com/dummy'));
        $request = Request::create('/api/stripe/identity', 'POST', ['draft_id' => $draft->id]);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session.store']);
        $response = app(PaymentController::class)->startIdentityVerification($request, app(OwnerOnboardingService::class));
        $this->assertSame(['url' => 'https://verify.stripe.com/dummy'], $response->getData(true));
        $this->assertSame($draft->id, $request->session()->get('owner_draft_id'));
    }

    public function test_token_api_without_session_can_start_identity_with_draft(): void
    {
        $user = User::factory()->create();
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('createIdentity')->once()->with((string) $user->id, url('/dashboard/profile'))
            ->andReturnUsing(function () {
                $this->assertSame(0, DB::transactionLevel());

                return new OwnerIdentityState('vs_created', 'requires_input', 'https://verify.stripe.com/dummy');
            });
        $token = $user->createToken('owner-test')->plainTextToken;
        $this->withToken($token)->postJson('/api/stripe/identity', ['draft_id' => $draft->id])
            ->assertOk()->assertExactJson(['url' => 'https://verify.stripe.com/dummy']);
        $this->assertSame('vs_created', $user->fresh()->stripe_identity_session_id);
    }

    public function test_legacy_gateway_uses_same_active_definition(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->once()->andReturn(new OwnerConnectState('acct_owner', true, false, true));
        $this->assertFalse(app(StripeGateway::class)->isAccountActive($user));
    }

    public function test_identity_failure_does_not_remember_foreign_or_failed_draft(): void
    {
        $user = User::factory()->create();
        $draft = $this->draft($user);
        $this->gateway->shouldReceive('createIdentity')->once()->andThrow(new \RuntimeException('private identity detail'));
        $request = Request::create('/api/stripe/identity', 'POST', ['draft_id' => $draft->id]);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session.store']);
        $response = app(PaymentController::class)->startIdentityVerification($request, app(OwnerOnboardingService::class));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($request->session()->has('owner_draft_id'));
        $this->assertNull($user->fresh()->stripe_identity_session_id);
        $this->assertSame('unverified', $user->fresh()->identity_status);
    }

    public function test_processing_identity_is_reused_and_remains_pending(): void
    {
        $user = User::factory()->create(['stripe_identity_session_id' => 'vs_processing']);
        $this->gateway->shouldReceive('retrieveIdentity')->once()->with('vs_processing')
            ->andReturn(new OwnerIdentityState('vs_processing', 'processing'));
        $this->actingAs($user)->postJson('/api/stripe/identity')->assertOk()
            ->assertExactJson(['url' => url('/dashboard/profile')]);
        $this->assertSame('pending', $user->fresh()->identity_status);
    }

    private function webhook(string $eventId, string $accountId = 'acct_owner', bool $oldActive = false, string $secret = 'whsec_owner_dummy'): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => 'account.updated',
            'data' => ['object' => ['id' => $accountId, 'object' => 'account',
                'charges_enabled' => $oldActive, 'payouts_enabled' => $oldActive, 'details_submitted' => true]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "$timestamp.$payload", $secret);

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t=$timestamp,v1=$signature",
        ], $payload);
    }

    public function test_real_signature_validation_rejects_invalid_webhook(): void
    {
        User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->webhook('evt_bad_signature', secret: 'wrong_dummy_secret')->assertBadRequest();
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_signed_webhook_replay_and_old_event_cannot_regress_current_state(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->twice()->with('acct_owner')
            ->andReturn(new OwnerConnectState('acct_owner', true, true, true));
        $this->webhook('evt_new')->assertOk();
        $this->webhook('evt_new')->assertOk();
        $this->webhook('evt_old', secret: 'whsec_connect_dummy')->assertOk();
        $this->assertSame('active', $user->fresh()->stripe_connect_status);
        $this->assertDatabaseCount('stripe_events', 2);
        Mail::assertSent(ConnectAccountActivated::class, 1);
    }

    public function test_activation_email_runs_after_commit_and_owner_lock_release_without_replay_duplicates(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->once()->with('acct_owner')
            ->andReturn(new OwnerConnectState('acct_owner', true, true, true));
        $pendingMail = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($pendingMail);
        $observed = null;
        $pendingMail->shouldReceive('send')->once()->andReturnUsing(function (ConnectAccountActivated $mail) use ($user, &$observed): void {
            $otherWorkerLock = Cache::store('database')->lock('stripe-owner:'.$user->id, 30);
            $acquired = $otherWorkerLock->get();
            try {
                $observed = [
                    'transaction_level' => DB::transactionLevel(),
                    'owner_lock_available' => $acquired,
                    'status' => $user->fresh()->stripe_connect_status,
                    'event_processed' => DB::table('stripe_events')->where('stripe_event_id', 'evt_mail_after_commit')
                        ->whereNotNull('processed_at')->exists(),
                    'recipient_id' => $mail->user->id,
                ];
            } finally {
                if ($acquired) {
                    $otherWorkerLock->release();
                }
            }
        });

        $this->webhook('evt_mail_after_commit')->assertOk();
        $this->assertSame([
            'transaction_level' => 0,
            'owner_lock_available' => true,
            'status' => 'active',
            'event_processed' => true,
            'recipient_id' => $user->id,
        ], $observed);
        $this->webhook('evt_mail_after_commit')->assertOk();
        $this->assertDatabaseCount('stripe_events', 1);
    }

    public function test_activation_email_failure_is_safely_logged_without_rollback_or_replay_resend(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->once()->with('acct_owner')
            ->andReturn(new OwnerConnectState('acct_owner', true, true, true));
        $pendingMail = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($pendingMail);
        $pendingMail->shouldReceive('send')->once()->with(Mockery::type(ConnectAccountActivated::class))
            ->andThrow(new \RuntimeException('private SMTP credential and recipient detail'));
        Log::spy();

        $this->webhook('evt_mail_failure')->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame('active', $user->fresh()->stripe_connect_status);
        $this->assertTrue(DB::table('stripe_events')->where('stripe_event_id', 'evt_mail_failure')
            ->whereNotNull('processed_at')->exists());
        $this->webhook('evt_mail_failure')->assertOk()->assertExactJson(['received' => true]);
        $this->assertDatabaseCount('stripe_events', 1);
        Log::shouldHaveReceived('warning')->once()->with('Courriel d’activation Stripe Connect non envoyé.', [
            'user_id' => $user->id,
            'stripe_event_id' => 'evt_mail_failure',
        ]);
        Log::shouldNotHaveReceived('error');
    }

    public function test_old_active_event_does_not_hide_current_restriction(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner', 'stripe_connect_status' => 'active']);
        $this->gateway->shouldReceive('retrieveAccount')->once()
            ->andReturn(new OwnerConnectState('acct_owner', false, false, true, false, 'requirements.past_due', true));
        $this->webhook('evt_old_active', oldActive: true)->assertOk();
        $this->assertSame('restricted', $user->fresh()->stripe_connect_status);
        Mail::assertNothingSent();
    }

    public function test_webhook_failure_is_retryable_and_not_recorded_as_processed(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->once()->ordered()->andThrow(new \RuntimeException('private SDK detail'));
        $this->webhook('evt_retry')->assertStatus(503)->assertDontSee('private SDK detail');
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('not_started', $user->fresh()->stripe_connect_status);
        $this->gateway->shouldReceive('retrieveAccount')->once()->ordered()->andReturn(new OwnerConnectState('acct_owner', true, true, true));
        $this->webhook('evt_retry')->assertOk();
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertSame('active', $user->fresh()->stripe_connect_status);
    }

    public function test_webhook_and_return_share_the_same_cross_worker_lock(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $lease = Cache::store('database')->lock('stripe-owner:'.$user->id, 300);
        $this->assertTrue($lease->get());
        // A second cache driver instance still observes the database lease.
        Cache::forgetDriver('database');
        try {
            $this->webhook('evt_contended')->assertStatus(503);
            $this->actingAs($user)->get('/stripe/onboarding/return')
                ->assertRedirect('/dashboard/profile')->assertSessionHas('error');
            $this->assertDatabaseCount('stripe_events', 0);
            $this->assertSame('not_started', $user->fresh()->stripe_connect_status);
        } finally {
            $lease->release();
        }
    }

    public function test_webhook_reads_stripe_outside_sql_transaction(): void
    {
        User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->gateway->shouldReceive('retrieveAccount')->once()->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel());

            return new OwnerConnectState('acct_owner', false, false, false);
        });
        $this->webhook('evt_no_sql_network')->assertOk();
    }

    public function test_unknown_account_webhook_has_no_effect_or_external_read(): void
    {
        $user = User::factory()->create(['stripe_connect_account_id' => 'acct_owner']);
        $this->webhook('evt_unknown', 'acct_unknown')->assertOk();
        $this->assertSame('not_started', $user->fresh()->stripe_connect_status);
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }
}
