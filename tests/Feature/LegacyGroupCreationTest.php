<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Features\Payment\Services\OwnerOnboardingException;
use App\Models\Group;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class LegacyGroupCreationTest extends TestCase
{
    private OwnerStripeGatewayInterface $stripe;

    private PaymentGatewayInterface $payments;

    private array $stripeReads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config([
            'services.stripe.secret' => 'sk_test_legacy_dummy_no_network',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.lock_table' => 'cache_locks',
        ]);
        Http::preventStrayRequests();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new \LogicException('Network forbidden in legacy tests.'));
        ApiRequestor::setHttpClient($transport);
        // No outer test transaction: the refresh must really precede SQL creation.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Mail::fake();
        $this->stripe = Mockery::mock(OwnerStripeGatewayInterface::class);
        $this->payments = Mockery::mock(PaymentGatewayInterface::class);
        $this->app->instance(OwnerStripeGatewayInterface::class, $this->stripe);
        $this->app->instance(PaymentGatewayInterface::class, $this->payments);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    #[DataProvider('endpoints')]
    public function test_real_refresh_allows_currently_verified_owner_and_preserves_creation(string $endpoint): void
    {
        $owner = $this->owner(['identity_status' => 'pending', 'stripe_connect_status' => 'pending']);
        $this->expectRefresh(new OwnerConnectState('acct_legacy_owner', true, true, true));
        $this->payments->shouldReceive('createProduct')->once()->with(Mockery::type(Group::class))
            ->andReturn(['product_id' => 'prod_legacy_owner']);
        $data = $this->data();

        $this->requestCreation($endpoint, $owner, $data)->assertRedirect(route('dashboard.subscriptions'));

        $this->assertSame([[0, 0], [0, 0]], $this->stripeReads);
        $this->assertSame('active', $owner->fresh()->stripe_connect_status);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('groups', 1);
        $group = Group::firstOrFail();
        $this->assertDatabaseHas('groups', [
            'id' => $group->id, 'owner_id' => $owner->id, 'status' => 'open',
            'total_price' => 2000, 'split_type' => 'custom', 'current_members' => 1, 'max_members' => 4,
        ]);
        $this->assertDatabaseCount('stripe_prices', 1);
        $this->assertDatabaseHas('stripe_prices', [
            'group_id' => $group->id, 'stripe_product_id' => 'prod_legacy_owner',
            'stripe_price_id' => null, 'unit_amount' => 2000, 'currency' => 'CAD',
        ]);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id, 'user_id' => $owner->id, 'role' => 'owner',
            'status' => 'active', 'share_amount' => 2000,
        ]);
        $this->assertSame($data['credential_password'], $group->credential_password);
        $this->assertNotSame($data['credential_password'], $group->getRawOriginal('credential_password'));
        $this->assertDatabaseCount('payments', 0);
    }

    #[DataProvider('remoteRestrictions')]
    public function test_remote_restriction_rejects_cached_active_owner_before_product_creation(string $endpoint, string $restriction): void
    {
        $owner = $this->owner();
        $account = match ($restriction) {
            'restricted' => new OwnerConnectState('acct_legacy_owner', false, false, true, disabledReason: 'requirements.past_due'),
            'charges_only' => new OwnerConnectState('acct_legacy_owner', true, false, true),
            'payouts_only' => new OwnerConnectState('acct_legacy_owner', false, true, true),
            default => new OwnerConnectState('acct_legacy_owner', true, true, true),
        };
        $identity = $restriction === 'identity_revoked' ? 'requires_input' : 'verified';
        $this->expectRefresh($account, $identity);
        $this->payments->shouldNotReceive('createProduct');

        $this->requestCreation($endpoint, $owner, $this->data())->assertRedirect('/dashboard/groups/create')
            ->assertSessionHas('error', 'Vérifiez votre identité et activez les versements avant de publier. Votre brouillon reste privé.');

        $this->assertSame([[0, 0], [0, 0]], $this->stripeReads);
        $this->assertSame($account->status(), $owner->fresh()->stripe_connect_status);
        $this->assertSame($restriction === 'identity_revoked' ? 'unverified' : 'verified', $owner->fresh()->identity_status);
        $this->assertNoPublication();
    }

    #[DataProvider('remoteOutages')]
    public function test_remote_outage_cannot_publish_or_expose_raw_errors(string $endpoint, string $failedRead): void
    {
        $owner = $this->owner();
        $this->stripe->shouldReceive('retrieveAccount')->once()->with('acct_legacy_owner')
            ->andReturnUsing(function () use ($failedRead): OwnerConnectState {
                $this->observeRead();
                if ($failedRead === 'account') {
                    throw new \RuntimeException('Sensitive upstream detail: dummy-secret');
                }

                return new OwnerConnectState('acct_legacy_owner', true, true, true);
            });
        if ($failedRead === 'identity') {
            $this->stripe->shouldReceive('retrieveIdentity')->once()->with('vs_legacy_owner')
                ->andReturnUsing(function (): never {
                    $this->observeRead();
                    throw new \RuntimeException('Sensitive upstream detail: dummy-secret');
                });
        } else {
            $this->stripe->shouldNotReceive('retrieveIdentity');
        }
        $this->payments->shouldNotReceive('createProduct');

        $this->requestCreation($endpoint, $owner, $this->data())->assertRedirect('/dashboard/groups/create')
            ->assertSessionHas('error', (new OwnerOnboardingException)->getMessage())
            ->assertSessionMissing('_old_input.credential_email')
            ->assertSessionMissing('_old_input.credential_password')
            ->assertSessionMissing('_old_input.credential_notes');

        $this->assertSame(array_fill(0, $failedRead === 'account' ? 1 : 2, [0, 0]), $this->stripeReads);
        $this->assertSame('active', $owner->fresh()->stripe_connect_status);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertNoPublication();
    }

    #[DataProvider('restrictionsDuringProduct')]
    public function test_current_owner_is_checked_again_after_product_creation(string $restriction): void
    {
        $owner = $this->owner();
        $this->expectRefresh(new OwnerConnectState('acct_legacy_owner', true, true, true));
        $productTransactionLevel = null;
        $this->payments->shouldReceive('createProduct')->once()->andReturnUsing(function () use ($owner, $restriction, &$productTransactionLevel): array {
            $productTransactionLevel = DB::transactionLevel();
            $changes = match ($restriction) {
                'connect' => ['stripe_connect_status' => 'restricted'],
                'identity' => ['identity_status' => 'unverified'],
                'email' => ['email_verified_at' => null],
                default => ['status' => 'suspended', 'suspended_until' => now()->addDay()],
            };
            // Simulates a changed row visible at the final read, not concurrent DB locking.
            User::whereKey($owner->id)->update($changes);

            return ['product_id' => 'prod_legacy_owner'];
        });

        try {
            app(GroupService::class)->create($owner, $this->data());
            $this->fail('The state observed after Stripe must prevent publication.');
        } catch (ValidationException|AuthorizationException $e) {
            $this->assertInstanceOf(in_array($restriction, ['connect', 'identity'], true)
                ? ValidationException::class : AuthorizationException::class, $e);
        }

        $this->assertSame([[0, 0], [0, 0]], $this->stripeReads);
        $this->assertSame(1, $productTransactionLevel);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNoPublication();
    }

    public function test_existing_verified_owner_without_identity_session_can_still_create(): void
    {
        $verifiedAt = now()->subYear()->startOfSecond();
        $owner = $this->owner(['stripe_identity_session_id' => null, 'identity_verified_at' => $verifiedAt]);
        $this->stripe->shouldReceive('retrieveAccount')->once()->with('acct_legacy_owner')
            ->andReturnUsing(function (): OwnerConnectState {
                $this->observeRead();

                return new OwnerConnectState('acct_legacy_owner', true, true, true);
            });
        $this->stripe->shouldNotReceive('retrieveIdentity');
        $this->payments->shouldReceive('createProduct')->once()->andReturn(['product_id' => 'prod_legacy_owner']);

        $group = app(GroupService::class)->create($owner, $this->data());

        $this->assertSame([[0, 0]], $this->stripeReads);
        $this->assertSame('open', $group->status);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertTrue($owner->fresh()->identity_verified_at->equalTo($verifiedAt));
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $owner->id, 'status' => 'active']);
    }

    public function test_cached_active_status_without_connected_account_cannot_create(): void
    {
        $owner = $this->owner(['stripe_connect_account_id' => null, 'stripe_identity_session_id' => null]);
        $this->stripe->shouldNotReceive('retrieveAccount');
        $this->stripe->shouldNotReceive('retrieveIdentity');
        $this->payments->shouldNotReceive('createProduct');

        try {
            app(GroupService::class)->create($owner, $this->data());
            $this->fail('An account identifier is required before publication.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activation', $e->errors());
        }

        $this->assertSame('not_started', $owner->fresh()->stripe_connect_status);
        $this->assertSame('verified', $owner->fresh()->identity_status);
        $this->assertNoPublication();
    }

    public static function endpoints(): array
    {
        return ['web' => ['/groups'], 'api' => ['/api/groups']];
    }

    public static function remoteRestrictions(): iterable
    {
        foreach (self::endpoints() as $name => [$endpoint]) {
            foreach (['restricted', 'charges_only', 'payouts_only', 'identity_revoked'] as $restriction) {
                yield $name.' '.$restriction => [$endpoint, $restriction];
            }
        }
    }

    public static function remoteOutages(): iterable
    {
        foreach (self::endpoints() as $name => [$endpoint]) {
            foreach (['account', 'identity'] as $failedRead) {
                yield $name.' '.$failedRead => [$endpoint, $failedRead];
            }
        }
    }

    public static function restrictionsDuringProduct(): array
    {
        return [['connect'], ['identity'], ['email'], ['suspended']];
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create([
            'identity_status' => 'verified', 'stripe_connect_status' => 'active',
            'stripe_connect_account_id' => 'acct_legacy_owner', 'stripe_identity_session_id' => 'vs_legacy_owner',
            ...$attributes,
        ]);
    }

    private function data(): array
    {
        return [
            'subscription_id' => Subscription::factory()->create(['currency' => 'CAD'])->id,
            'name' => 'Legacy owner group', 'tier' => 'standard', 'max_members' => 4,
            'total_price' => 2000, 'split_type' => 'custom', 'visibility' => 'public',
            'renewal_date' => now()->addMonth()->toDateString(), 'auto_renew' => true,
            'credential_email' => 'synthetic@example.test', 'credential_password' => 'synthetic-credential',
            'credential_notes' => 'Synthetic private note',
        ];
    }

    private function requestCreation(string $endpoint, User $owner, array $data): TestResponse
    {
        if ($endpoint === '/api/groups') {
            $this->withToken($owner->createToken('legacy-owner-test')->plainTextToken);
        } else {
            $this->actingAs($owner);
        }

        return $this->from('/dashboard/groups/create')->postJson($endpoint, $data);
    }

    private function expectRefresh(OwnerConnectState $account, string $identity = 'verified'): void
    {
        $this->stripe->shouldReceive('retrieveAccount')->once()->with('acct_legacy_owner')
            ->andReturnUsing(function () use ($account): OwnerConnectState {
                $this->observeRead();

                return $account;
            });
        $this->stripe->shouldReceive('retrieveIdentity')->once()->with('vs_legacy_owner')
            ->andReturnUsing(function () use ($identity): OwnerIdentityState {
                $this->observeRead();

                return new OwnerIdentityState('vs_legacy_owner', $identity);
            });
    }

    private function observeRead(): void
    {
        $this->stripeReads[] = [DB::transactionLevel(), Group::count()];
    }

    private function assertNoPublication(): void
    {
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('stripe_prices', 0);
        $this->assertDatabaseCount('group_members', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
