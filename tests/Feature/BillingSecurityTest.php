<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\PaymentService;
use App\Jobs\CheckCredentialsProvided;
use App\Mail\PaymentConfirmed;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\StripePrice;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BillingTestCase;

class BillingSecurityTest extends BillingTestCase
{
    private MockInterface $writer;

    private MockInterface $reader;

    private MockInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['writer' => CheckoutGatewayInterface::class, 'reader' => BillingReadGatewayInterface::class, 'gateway' => PaymentGatewayInterface::class] as $property => $contract) {
            $this->{$property} = Mockery::mock($contract);
            $this->instance($contract, $this->{$property});
        }
    }

    private function group(array $attributes = []): Group
    {
        $owner = User::factory()->create(['stripe_connect_account_id' => 'acct_owner', 'stripe_connect_status' => 'active']);
        $group = Group::factory()->withCredentials()->create(['owner_id' => $owner->id, 'total_price' => 2000, ...$attributes]);
        $group->subscription->update(['currency' => 'CAD']);
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $owner->id, 'role' => 'owner']);
        StripePrice::create(['group_id' => $group->id, 'stripe_product_id' => 'prod_test', 'unit_amount' => 2000, 'currency' => 'CAD']);

        return $group;
    }

    private function member(array $attributes = []): GroupMember
    {
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_test']);

        return GroupMember::factory()->create([
            'group_id' => $this->group(['current_members' => 2])->id, 'user_id' => $payer->id,
            'status' => 'pending_payment', 'stripe_subscription_id' => 'sub_test',
            'stripe_customer_id' => 'cus_test', 'subscription_status' => 'active', ...$attributes,
        ]);
    }

    private function snapshots(string $status = 'active', bool $refunded = false, array $invoiceChanges = []): array
    {
        $invoice = [
            'id' => 'in_test', 'object' => 'invoice', 'customer' => 'cus_test', 'currency' => 'cad',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_test']],
            'status' => 'paid', 'amount_paid' => 1000, 'amount_due' => 1000, 'amount_remaining' => 0,
            'period_start' => now()->startOfMonth()->timestamp, 'period_end' => now()->addMonthNoOverflow()->startOfMonth()->timestamp,
            'status_transitions' => ['paid_at' => now()->timestamp],
            'confirmation_secret' => ['client_secret' => 'pi_test_secret_PRIVATE'], ...$invoiceChanges,
        ];
        $subscription = ['id' => 'sub_test', 'customer' => 'cus_test', 'currency' => 'cad', 'status' => $status,
            'latest_invoice' => $invoice, 'items' => ['data' => [['id' => 'si_test', 'current_period_end' => now()->addMonth()->timestamp]]]];
        $intent = ['id' => 'pi_test', 'customer' => 'cus_test', 'currency' => 'cad', 'status' => 'succeeded', 'amount_received' => 1000,
            'latest_charge' => ['id' => 'ch_test', 'refunded' => $refunded, 'amount_refunded' => $refunded ? 1000 : 0]];

        return [$subscription, $invoice, $intent];
    }

    private function mockSnapshots(array $snapshots, int $times = 1): void
    {
        [$subscription, $invoice, $intent] = $snapshots;
        $this->reader->shouldReceive('retrieveSubscription')->times($times)->with('sub_test')->andReturn($subscription);
        $this->reader->shouldReceive('retrieveInvoice')->times($times)->with('in_test')->andReturn($invoice);
        $this->reader->shouldReceive('invoicePayments')->times($times)->with('in_test')->andReturn([[
            'invoice' => 'in_test', 'status' => 'paid', 'amount_paid' => 1000,
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_test'],
        ]]);
        $this->reader->shouldReceive('retrievePaymentIntent')->times($times)->with('pi_test')->andReturn($intent);
    }

    #[DataProvider('restrictedGroups')]
    public function test_subscription_cannot_bypass_group_access(array $attributes, int $expected): void
    {
        $group = $this->group($attributes);
        $this->actingAs(User::factory()->create())->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertStatus($expected);
        $this->assertDatabaseCount('subscription_attempts', 0);
        $this->assertSame(1, $group->members()->count());
    }

    public static function restrictedGroups(): array
    {
        return [
            [['visibility' => 'private', 'invite_token' => 'valid-token'], 403],
            [['visibility' => 'invite_only', 'invite_token' => 'valid-token'], 403],
            [['status' => 'closed'], 403], [['status' => 'full', 'current_members' => 6], 409],
        ];
    }

    public function test_retired_service_refuses_checkout_before_any_provider_operation(): void
    {
        $group = $this->group();
        $group->subscription->update(['is_active' => false]);
        $this->actingAs(User::factory()->create())->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertForbidden();
        $this->assertDatabaseCount('subscription_attempts', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(1, $group->members()->count());
    }

    public function test_retirement_during_remote_account_check_cannot_start_a_new_commitment(): void
    {
        $group = $this->group();
        $this->gateway->shouldReceive('isAccountActive')->once()->andReturnUsing(function () use ($group): bool {
            $group->subscription->update(['is_active' => false]);

            return true;
        });
        $this->actingAs(User::factory()->create())->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertForbidden();
        $this->assertDatabaseCount('subscription_attempts', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(1, $group->members()->count());
    }

    public function test_unverified_and_suspended_accounts_cannot_subscribe(): void
    {
        $group = $this->group();
        $this->actingAs(User::factory()->unverified()->create())->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertForbidden();
        $this->actingAs(User::factory()->create(['status' => 'banned']))->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertForbidden();
        $this->assertDatabaseCount('subscription_attempts', 0);
    }

    public function test_canceled_subscription_cannot_be_confirmed_by_a_historical_paid_invoice(): void
    {
        $member = $this->member();
        $this->reader->shouldReceive('retrieveSubscription')->once()->with('sub_test')->andReturn($this->snapshots('canceled')[0]);
        $this->actingAs($member->user)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertUnprocessable();
        $this->assertSame('left', $member->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        Mail::assertNothingSent();
    }

    #[DataProvider('terminalMembers')]
    public function test_locally_terminated_membership_cannot_be_reactivated(string $status, bool $requested): void
    {
        $member = $this->member(['status' => $status, 'cancellation_requested_at' => $requested ? now() : null]);
        $this->actingAs($member->user)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertUnprocessable();
        $this->assertSame($status, $member->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function terminalMembers(): array
    {
        return [['left', false], ['kicked', false], ['active', true]];
    }

    public function test_confirm_requires_ownership_of_the_subscription(): void
    {
        $this->member();
        $this->actingAs(User::factory()->create())->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_refunded_payment_cannot_restore_credentials(): void
    {
        $member = $this->member();
        $this->mockSnapshots($this->snapshots(refunded: true));
        $this->actingAs($member->user)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertUnprocessable();
        $this->assertSame('left', $member->fresh()->status);
        $this->assertNotNull($member->fresh()->cancellation_requested_at);
        $this->assertDatabaseHas('payments', ['status' => 'refunded', 'stripe_invoice_id' => 'in_test']);
        $this->getJson('/api/groups/'.$member->group_id.'/credentials')->assertForbidden();
    }

    public function test_modern_invoice_payment_is_recorded_once_and_confirmation_is_not_spammable(): void
    {
        $member = $this->member();
        $this->mockSnapshots($this->snapshots(), 2);
        $this->actingAs($member->user);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertOk();
        }
        $this->assertSame('active', $member->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, $member->user->fresh()->completed_payments_count);
        Mail::assertSent(PaymentConfirmed::class, 1);
        $this->getJson('/api/groups/'.$member->group_id.'/credentials')->assertOk();
    }

    #[DataProvider('mismatchedReferences')]
    public function test_mismatched_canonical_invoice_is_not_accepted(array $changes): void
    {
        $member = $this->member();
        [$subscription, $invoice] = $this->snapshots(invoiceChanges: $changes);
        $this->reader->shouldReceive('retrieveSubscription')->once()->andReturn($subscription);
        $this->reader->shouldReceive('retrieveInvoice')->once()->andReturn($invoice);
        $this->actingAs($member->user)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertStatus(503);
        $this->assertSame('pending_payment', $member->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function mismatchedReferences(): array
    {
        return [[['customer' => 'cus_other']], [['currency' => 'usd']], [['parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_other']]]]];
    }

    public function test_active_subscription_with_unpaid_latest_invoice_is_not_entitled(): void
    {
        $member = $this->member(['status' => 'active']);
        [$subscription, $invoice] = $this->snapshots(invoiceChanges: ['status' => 'open', 'amount_paid' => 0, 'amount_remaining' => 1000]);
        $this->reader->shouldReceive('retrieveSubscription')->once()->andReturn($subscription);
        $this->reader->shouldReceive('retrieveInvoice')->once()->andReturn($invoice);
        $this->actingAs($member->user)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertUnprocessable();
        $this->assertSame('suspended', $member->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    private function checkoutMocks(int $writes = 1): void
    {
        $this->gateway->shouldReceive('isAccountActive')->times($writes)->andReturn(true);
        $this->writer->shouldReceive('attachPaymentMethod')->times($writes)->with('pm_test', 'cus_test');
        $this->writer->shouldReceive('createPrice')->once()->andReturnUsing(function (array $parameters, string $key) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(1000, $parameters['unit_amount']);
            $this->assertStringEndsWith(':price', $key);

            return 'price_test';
        });
    }

    public function test_lost_subscription_response_reuses_durable_key_parameters_and_reserved_seat(): void
    {
        $group = $this->group(['visibility' => 'private', 'invite_token' => 'valid-token']);
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_test']);
        $this->checkoutMocks(2);
        $calls = [];
        $this->writer->shouldReceive('createSubscription')->twice()->andReturnUsing(function (array $parameters, string $key) use (&$calls) {
            $this->assertSame(0, DB::transactionLevel());
            $calls[] = [$parameters, $key];
            if (count($calls) === 1) {
                throw new RuntimeException('PRIVATE_PROVIDER_ERROR');
            }

            return $this->snapshots()[0];
        });
        $this->actingAs($payer)->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test', 'invite_token' => 'valid-token'])
            ->assertStatus(503)->assertDontSee('PRIVATE_PROVIDER_ERROR');
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertSame(2, $group->fresh()->current_members);
        $group->update(['total_price' => 8000]);
        $this->mockSnapshots($this->snapshots());
        $this->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_different', 'invite_token' => 'valid-token'])->assertOk();
        $this->assertSame($calls[0], $calls[1]);
        $this->assertSame(5, $calls[0][0]['application_fee_percent']);
        $this->assertSame('acct_owner', $calls[0][0]['transfer_data']['destination']);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertSame(2, $group->members()->count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertStringNotContainsString('pm_test', DB::table('subscription_attempts')->value('parameters'));
    }

    #[DataProvider('catalogueAvailability')]
    public function test_existing_subscription_is_reused_without_creating_another_charge(bool $active): void
    {
        $member = $this->member();
        $member->group->subscription->update(['is_active' => $active]);
        $snapshots = $this->snapshots();
        $this->reader->shouldReceive('retrieveSubscription')->once()->with('sub_test')->andReturn($snapshots[0]);
        $this->mockSnapshots($snapshots);
        $this->actingAs($member->user)->postJson('/api/groups/'.$member->group_id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertOk();
        $this->assertDatabaseCount('subscription_attempts', 0);
        $this->assertDatabaseCount('payments', 1);
    }

    public static function catalogueAvailability(): array
    {
        return ['available' => [true], 'retired' => [false]];
    }

    public function test_pending_refund_is_not_marked_refunded_and_retry_only_reads_existing_refund(): void
    {
        $member = $this->member(['status' => 'active']);
        $payment = Payment::factory()->create(['user_id' => $member->user_id, 'group_id' => $member->group_id, 'stripe_payment_intent_id' => 'pi_test']);
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('sub_test');
        $this->gateway->shouldReceive('refundPayment')->once()->with('pi_test', null, Mockery::type('string'))->andReturn(['refund_id' => 're_test', 'status' => 'pending']);
        $result = app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');
        $this->assertSame('completed', $result->status);
        $this->assertNull($result->refunded_at);
        $this->assertSame('left', $member->fresh()->status);
        $this->reader->shouldReceive('retrieveRefund')->once()->with('re_test')->andReturn(['id' => 're_test', 'payment_intent' => 'pi_test', 'status' => 'succeeded']);
        $result = app(PaymentService::class)->refundPayment($payment, 'different_reason');
        $this->assertSame('refunded', $result->status);
        $this->assertSame('dispute_resolved', $result->refund_reason);
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_refund_timeout_retries_with_identical_key_without_double_releasing_seat(): void
    {
        $member = $this->member(['status' => 'active']);
        $payment = Payment::factory()->create(['user_id' => $member->user_id, 'group_id' => $member->group_id, 'stripe_payment_intent_id' => 'pi_test']);
        $keys = [];
        $this->gateway->shouldReceive('cancelSubscription')->once();
        $this->gateway->shouldReceive('refundPayment')->twice()->andReturnUsing(function ($id, $amount, $key) use (&$keys) {
            $this->assertSame(0, DB::transactionLevel());
            $keys[] = $key;
            if (count($keys) === 1) {
                throw new RuntimeException('lost response');
            }

            return ['refund_id' => 're_test', 'status' => 'succeeded'];
        });
        try {
            app(PaymentService::class)->refundPayment($payment, 'test');
            $this->fail('Expected ambiguous response');
        } catch (RuntimeException $e) {
            $this->assertSame('lost response', $e->getMessage());
        }
        $this->assertSame('completed', $payment->fresh()->status);
        app(PaymentService::class)->refundPayment($payment, 'test');
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_cancellation_timeout_does_not_claim_canceled_and_is_retried_on_refund_replay(): void
    {
        $member = $this->member(['status' => 'active']);
        $payment = Payment::factory()->create(['user_id' => $member->user_id, 'group_id' => $member->group_id]);
        $this->gateway->shouldReceive('refundPayment')->once()->andReturn(['refund_id' => 're_test', 'status' => 'succeeded']);
        $this->gateway->shouldReceive('cancelSubscription')->once()->ordered()->andThrow(new RuntimeException('timeout'));
        app(PaymentService::class)->refundPayment($payment, 'test');
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('cancellation_pending', $member->fresh()->subscription_status);
        $this->assertNotNull($member->fresh()->cancellation_requested_at);
        $this->gateway->shouldReceive('cancelSubscription')->once()->ordered();
        app(PaymentService::class)->refundPayment($payment, 'test');
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertSame(1, $member->group->fresh()->current_members);
    }

    public function test_queue_outage_after_payment_commit_keeps_a_durable_check_for_retry(): void
    {
        $member = $this->member();
        $this->mockSnapshots($this->snapshots(), 2);
        $dispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('PRIVATE_QUEUE_FAILURE'));
        $this->actingAs($member->user)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])
            ->assertStatus(503)->assertDontSee('PRIVATE_QUEUE_FAILURE');
        $payment = Payment::firstOrFail();
        $this->assertNotNull($payment->credentials_check_due_at);
        $this->assertNull($payment->credentials_check_queued_at);
        Bus::swap($dispatcher);
        $this->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertNotNull($payment->fresh()->credentials_check_queued_at);
        Bus::assertDispatched(CheckCredentialsProvided::class, 1);
    }

    public function test_deleted_owner_blocks_checkout_before_any_provider_call(): void
    {
        $group = $this->group();
        $group->owner->delete();
        $this->actingAs(User::factory()->create())->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_test'])->assertNotFound();
        $this->assertDatabaseCount('subscription_attempts', 0);
    }

    public function test_production_payment_outage_still_returns_safe_json_not_an_html_error_page(): void
    {
        $member = $this->member();
        $this->reader->shouldReceive('retrieveSubscription')->once()->andThrow(new RuntimeException('PRIVATE_PROVIDER_DATA'));
        $this->actingAs($member->user);
        config(['app.debug' => false]);
        $this->app->instance('env', 'production');
        try {
            $this->withMiddleware(VerifyCsrfToken::class);
            // A bearer token is the real API authentication path; CSRF remains
            // enforced for cookie requests, which are covered separately.
            $token = $member->user->createToken('production-json-test')->plainTextToken;
            $this->withToken($token)->postJson('/api/subscriptions/confirm', ['subscription_id' => 'sub_test'])
                ->assertStatus(503)->assertHeader('Content-Type', 'application/json')->assertDontSee('PRIVATE_PROVIDER_DATA');
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
