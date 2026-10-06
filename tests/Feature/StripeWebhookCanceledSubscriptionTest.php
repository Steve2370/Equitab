<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/** Signed HTTP requests, real reconciliation, fake provider boundaries only. */
class StripeWebhookCanceledSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $billing;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.secret' => 'sk_test_webhook_no_network',
            'services.stripe.webhook_secret' => 'whsec_cancellation_synthetic',
            'services.stripe.connect_webhook_secret' => null,
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.lock_table' => 'cache_locks',
        ]);
        Mail::fake();
        Bus::fake();
        Http::preventStrayRequests();
        $original = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new RuntimeException('Stripe network forbidden.'));
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
        $this->billing = Mockery::mock(BillingReadGatewayInterface::class);
        $this->instance(BillingReadGatewayInterface::class, $this->billing);
    }

    private function webhook(string $subscriptionId, string $eventId = 'evt_cancellation'): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => $subscriptionId, 'object' => 'subscription', 'status' => 'canceled']],
        ], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', "$time.$payload", 'whsec_cancellation_synthetic');

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature",
        ], $payload);
    }

    private function canceledSnapshot(GroupMember $member): array
    {
        return [
            'id' => $member->stripe_subscription_id, 'object' => 'subscription', 'status' => 'canceled',
            'customer' => $member->stripe_customer_id, 'latest_invoice' => null,
        ];
    }

    public function test_decrements_current_members_when_member_was_active(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $member = GroupMember::factory()->for($group)->withStripeSubscription('sub_abc')->create([
            'status' => 'active', 'stripe_customer_id' => 'cus_current',
        ]);
        $this->billing->shouldReceive('retrieveSubscription')->once()->with('sub_abc')->andReturn($this->canceledSnapshot($member));

        $this->webhook('sub_abc')->assertOk();

        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertDatabaseCount('stripe_events', 1);
    }

    public function test_does_not_decrement_again_when_member_was_already_left(): void
    {
        $group = Group::factory()->create(['current_members' => 1]);
        $member = GroupMember::factory()->for($group)->withStripeSubscription('sub_def')->create([
            'status' => 'left', 'stripe_customer_id' => 'cus_current',
        ]);
        $this->billing->shouldReceive('retrieveSubscription')->twice()->with('sub_def')->andReturn($this->canceledSnapshot($member));

        $this->webhook('sub_def')->assertOk();
        $this->webhook('sub_def')->assertOk();
        $this->webhook('sub_def', 'evt_second_cancellation')->assertOk();

        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertDatabaseCount('stripe_events', 2);
    }

    public function test_does_nothing_when_no_member_matches_the_subscription(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);

        $this->webhook('sub_unknown')->assertOk();

        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_failed_cancellation_processing_does_not_consume_event_and_retry_changes_state_once(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $member = GroupMember::factory()->for($group)->withStripeSubscription('sub_retry')->create([
            'status' => 'active', 'stripe_customer_id' => 'cus_current',
        ]);
        $this->billing->shouldReceive('retrieveSubscription')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable.'));

        $this->webhook('sub_retry', 'evt_retry')->assertStatus(503);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame(2, $group->fresh()->current_members);

        $this->billing->shouldReceive('retrieveSubscription')->once()->ordered()->andReturn($this->canceledSnapshot($member));
        $this->webhook('sub_retry', 'evt_retry')->assertOk();
        $this->webhook('sub_retry', 'evt_retry')->assertOk();

        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertDatabaseCount('stripe_events', 1);
    }
}
