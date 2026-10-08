<?php

namespace Tests\Support;

use App\Features\Group\Contracts\GroupProductGateway;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Models\GroupDraft;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Monolog\Handler\TestHandler;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/** Fixtures and external boundaries used only by the new draft tests. */
abstract class GroupDraftTestCase extends TestCase
{
    use RefreshDatabase;

    protected MockInterface $productGateway;

    protected MockInterface $ownerStripe;

    protected TestHandler $draftLogs;

    protected function beforeRefreshingDatabase(): void
    {
        // Fail before migrations if an exported variable or cached config defeats isolation.
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        Http::preventStrayRequests();
        Bus::fake();
        Mail::fake();
        Notification::fake();

        $originalTransport = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldNotReceive('request');
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($originalTransport));

        $payment = Mockery::mock(PaymentGatewayInterface::class);
        foreach (get_class_methods(PaymentGatewayInterface::class) as $method) {
            $payment->shouldNotReceive($method);
        }
        $this->instance(PaymentGatewayInterface::class, $payment);

        $this->productGateway = Mockery::mock(GroupProductGateway::class);
        $this->productGateway->shouldReceive('ensureProduct')->never()->byDefault();
        $this->instance(GroupProductGateway::class, $this->productGateway);

        // The onboarding service is final: exercise it with a fake external boundary.
        $this->ownerStripe = Mockery::mock(OwnerStripeGatewayInterface::class);
        $this->ownerStripe->shouldNotReceive('createAccount', 'createAccountLink', 'createIdentity');
        $this->ownerStripe->shouldReceive('retrieveAccount')->with('acct_draft_test')
            ->andReturn(new OwnerConnectState('acct_draft_test', true, true, true))->byDefault();
        $this->ownerStripe->shouldReceive('retrieveIdentity')->with('vs_draft_test')
            ->andReturn(new OwnerIdentityState('vs_draft_test', 'verified'))->byDefault();
        $this->instance(OwnerStripeGatewayInterface::class, $this->ownerStripe);

        config([
            'logging.default' => 'draft_tests',
            'logging.channels.draft_tests' => [
                'driver' => 'monolog',
                'handler' => TestHandler::class,
            ],
        ]);
        $this->draftLogs = Log::channel('draft_tests')->getLogger()->getHandlers()[0];
    }

    public static function draftEndpoints(): array
    {
        return ['web' => ['/group-drafts'], 'api' => ['/api/group-drafts']];
    }

    protected function authenticateDraftOwner(User $owner, string $base): void
    {
        if (str_starts_with($base, '/api/')) {
            Sanctum::actingAs($owner);
        } else {
            $this->actingAs($owner, 'web');
        }
    }

    protected function readyOwner(array $attributes = []): User
    {
        $suffix = User::count() === 0 ? '' : '_'.User::count();

        return User::factory()->create([
            'identity_status' => 'verified',
            'stripe_connect_status' => 'active',
            'stripe_connect_account_id' => 'acct_draft_test'.$suffix,
            'stripe_identity_session_id' => 'vs_draft_test'.$suffix,
            ...$attributes,
        ]);
    }

    protected function validDraftData(?Subscription $subscription = null): array
    {
        $subscription ??= Subscription::factory()->create(['max_members' => 6]);

        return [
            'subscription_id' => $subscription->id,
            'name' => 'Brouillon privé de test',
            'description' => 'Description réservée au propriétaire',
            'tier' => 'standard',
            'max_members' => 4,
            'total_price' => 1999,
            'currency' => 'CAD',
            'split_type' => 'equal',
            'visibility' => 'public',
            'renewal_date' => now()->addMonth()->toDateString(),
            'auto_renew' => true,
        ];
    }

    protected function draftFor(User $owner, ?array $data = null, array $attributes = []): GroupDraft
    {
        $draft = new GroupDraft;
        $draft->forceFill([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'data' => $data ?? $this->validDraftData(),
            'version' => 1,
            'status' => 'draft',
            ...$attributes,
        ])->save();

        return $draft;
    }

    protected function assertNoPublishedEffects(): void
    {
        foreach (['groups', 'group_members', 'stripe_prices', 'payments', 'transactions', 'invitations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    protected function assertNoDraftSideEffects(): void
    {
        $this->assertNoPublishedEffects();
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    protected function assertSecretsAbsent(GroupDraft $draft, array $secrets, TestResponse ...$responses): void
    {
        $surfaces = [
            json_encode(DB::table('group_drafts')->where('id', $draft->id)->first(), JSON_THROW_ON_ERROR),
            $draft->fresh()->toJson(),
            json_encode(session()->all(), JSON_THROW_ON_ERROR),
            json_encode($this->draftLogs->getRecords(), JSON_THROW_ON_ERROR),
        ];
        foreach ($responses as $response) {
            $surfaces[] = $response->getContent();
        }
        foreach ($secrets as $secret) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($secret, $surface);
            }
        }
    }
}
