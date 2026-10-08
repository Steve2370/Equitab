<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingUnavailable;
use App\Features\Payment\Services\PaymentRefundService;
use App\Features\Payment\Services\PaymentService;
use App\Features\Payment\Services\PaymentSynchronizationService;
use App\Features\Payment\Services\SubscriptionCheckoutService;
use App\Jobs\CheckCredentialsProvided;
use App\Jobs\RecalculateGroupPrices;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\StripePrice;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\Util\ApiVersion;
use Tests\Support\BillingTestCase;

class CurrencyBillingTest extends BillingTestCase
{
    private MockInterface $writer;

    private MockInterface $reader;

    private MockInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.eur_enabled' => false]);
        foreach (['writer' => CheckoutGatewayInterface::class, 'reader' => BillingReadGatewayInterface::class, 'gateway' => PaymentGatewayInterface::class] as $property => $contract) {
            $this->{$property} = Mockery::mock($contract);
            $this->instance($contract, $this->{$property});
        }
    }

    private function group(string $currency = 'EUR'): Group
    {
        $owner = User::factory()->create(['stripe_connect_account_id' => 'acct_currency', 'stripe_connect_status' => 'active']);
        $group = Group::factory()->withCredentials()->create(['owner_id' => $owner->id, 'currency' => $currency, 'total_price' => 2001]);
        $group->subscription->update(['currency' => $currency === 'EUR' ? 'CAD' : 'EUR']);
        GroupMember::factory()->owner()->create(['group_id' => $group->id, 'user_id' => $owner->id]);
        StripePrice::create(['group_id' => $group->id, 'stripe_product_id' => 'prod_currency', 'unit_amount' => 2001, 'currency' => $currency]);

        return $group;
    }

    private function member(string $currency = 'EUR'): GroupMember
    {
        $group = $this->group($currency);
        $group->update(['current_members' => 2]);
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_currency']);

        return GroupMember::factory()->pendingPayment()->create([
            'group_id' => $group->id, 'user_id' => $payer->id,
            'stripe_subscription_id' => 'sub_currency', 'stripe_subscription_item_id' => 'si_currency',
            'stripe_customer_id' => 'cus_currency', 'subscription_status' => 'incomplete', 'share_amount' => 1001,
        ]);
    }

    private function attempt(Group $group, User $payer, string $currency): object
    {
        DB::table('subscription_attempts')->insert([
            'id' => (string) Str::uuid(), 'group_id' => $group->id, 'user_id' => $payer->id,
            'parameters' => Crypt::encryptString(json_encode([
                'method' => 'pm_currency', 'destination' => 'acct_currency', 'anchor' => now()->addMonth()->timestamp,
                'price' => ['currency' => strtolower($currency), 'unit_amount' => 1001,
                    'recurring' => ['interval' => 'month'], 'product' => 'prod_currency'],
            ], JSON_THROW_ON_ERROR)), 'started_at' => now(),
        ]);

        return DB::table('subscription_attempts')->where('group_id', $group->id)->where('user_id', $payer->id)->first();
    }

    private function snapshots(string $currency = 'eur', string $suffix = 'currency', int $amount = 333): array
    {
        $end = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $invoice = [
            'id' => 'in_'.$suffix, 'customer' => 'cus_currency', 'currency' => $currency,
            'subscription' => 'sub_currency', 'status' => 'paid',
            'amount_paid' => $amount, 'amount_due' => $amount, 'amount_remaining' => 0,
            'period_start' => now()->timestamp, 'period_end' => $end,
            'status_transitions' => ['paid_at' => now()->timestamp], 'payment_intent' => 'pi_'.$suffix,
        ];
        $subscription = [
            'id' => 'sub_currency', 'customer' => 'cus_currency', 'currency' => $currency,
            'status' => 'active', 'latest_invoice' => $invoice['id'],
            'items' => ['data' => [['id' => 'si_currency', 'current_period_end' => $end, 'price' => ['currency' => $currency]]]],
        ];
        $intent = [
            'id' => 'pi_'.$suffix, 'customer' => 'cus_currency', 'currency' => $currency,
            'status' => 'succeeded', 'amount_received' => $amount,
            'latest_charge' => ['id' => 'ch_'.$suffix, 'refunded' => false, 'amount_refunded' => 0],
        ];

        return [$subscription, $invoice, $intent];
    }

    private function readSnapshots(array $snapshots): void
    {
        [$subscription, $invoice, $intent] = $snapshots;
        $this->reader->shouldReceive('retrieveSubscription')->with('sub_currency')->andReturn($subscription);
        $this->reader->shouldReceive('retrieveInvoice')->with($invoice['id'])->andReturn($invoice);
        $this->reader->shouldReceive('retrievePaymentIntent')->with($intent['id'])->andReturn($intent);
    }

    public static function currencies(): array
    {
        return [['CAD'], ['EUR']];
    }

    #[DataProvider('currencies')]
    public function test_new_checkout_uses_native_currency_and_waits_for_payment_confirmation(string $currency): void
    {
        config(['payments.eur_enabled' => $currency === 'EUR']);
        $group = $this->group($currency);
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_currency']);
        $this->gateway->shouldReceive('isAccountActive')->once()->andReturn(true);
        $this->writer->shouldReceive('attachPaymentMethod')->once()->with('pm_currency', 'cus_currency');
        $this->writer->shouldReceive('createPrice')->once()->andReturnUsing(function (array $parameters, string $key) use ($currency) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(['unit_amount' => 1001, 'currency' => strtolower($currency), 'recurring' => ['interval' => 'month'], 'product' => 'prod_currency'], $parameters);
            $this->assertSame(DB::table('subscription_attempts')->value('id').':price', $key);

            return 'price_currency';
        });
        $raw = $this->snapshots(strtolower($currency))[0];
        $raw['status'] = 'incomplete';
        $raw['latest_invoice'] = ['id' => 'in_currency', 'currency' => strtolower($currency), 'status' => 'open',
            'amount_due' => 333, 'confirmation_secret' => ['client_secret' => 'synthetic_secret']];
        $this->writer->shouldReceive('createSubscription')->once()->andReturnUsing(function (array $parameters, string $key) use ($raw) {
            $this->assertSame(5, $parameters['application_fee_percent']);
            $this->assertSame(['destination' => 'acct_currency'], $parameters['transfer_data']);
            $this->assertSame('create_prorations', $parameters['proration_behavior']);
            $this->assertSame('default_incomplete', $parameters['payment_behavior']);
            $this->assertSame([['price' => 'price_currency']], $parameters['items']);
            $this->assertSame(DB::table('subscription_attempts')->value('id').':subscription', $key);

            return $raw;
        });
        $this->reader->shouldReceive('retrieveSubscription')->once()->with('sub_currency')->andReturn($raw);

        $this->actingAs($payer)->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_currency'])
            ->assertOk()->assertJsonPath('currency', $currency)->assertJsonPath('invoice_paid', false)
            ->assertJsonPath('client_secret', 'synthetic_secret')->assertJsonPath('amount_today', 333);
        $this->assertDatabaseCount('payments', 0);
        $this->assertFalse($group->members()->where('user_id', $payer->id)->where('status', 'active')->exists());
    }

    public function test_disabled_eur_rejects_new_attempt_before_reserving_a_seat_or_contacting_stripe(): void
    {
        $group = $this->group();
        $this->actingAs(User::factory()->create())->postJson('/api/groups/'.$group->id.'/subscribe', ['payment_method_id' => 'pm_currency'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('subscription_attempts', 0);
        $this->assertDatabaseCount('billing_customer_attempts', 0);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame(1, $group->members()->count());
    }

    public static function lostResponses(): array
    {
        return [['price'], ['subscription']];
    }

    #[DataProvider('lostResponses')]
    public function test_eur_retry_after_flag_is_disabled_keeps_all_committed_parameters_and_keys(string $lostResponse): void
    {
        config(['payments.eur_enabled' => true]);
        $group = $this->group();
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_currency']);
        $this->gateway->shouldReceive('isAccountActive')->twice()->andReturn(true);
        $this->writer->shouldReceive('attachPaymentMethod')->twice()->with('pm_currency', 'cus_currency');
        $prices = [];
        $subscriptions = [];
        $this->writer->shouldReceive('createPrice')->times($lostResponse === 'price' ? 2 : 1)
            ->andReturnUsing(function (array $parameters, string $key) use (&$prices, $lostResponse) {
                $prices[] = [$parameters, $key];
                if ($lostResponse === 'price' && count($prices) === 1) {
                    throw new RuntimeException('Lost price response.');
                }

                return 'price_currency';
            });
        $this->writer->shouldReceive('createSubscription')->times($lostResponse === 'subscription' ? 2 : 1)
            ->andReturnUsing(function (array $parameters, string $key) use (&$subscriptions, $lostResponse) {
                $subscriptions[] = [$parameters, $key];
                if ($lostResponse === 'subscription' && count($subscriptions) === 1) {
                    throw new RuntimeException('Lost subscription response.');
                }

                return $this->snapshots()[0];
            });
        try {
            app(SubscriptionCheckoutService::class)->start($payer, $group, 'pm_currency');
            $this->fail('The first response must be lost.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('Lost ', $error->getMessage());
        }
        $before = DB::table('subscription_attempts')->first();
        config(['payments.eur_enabled' => false]);
        $group->update(['total_price' => 9000]);
        $group->subscription->update(['currency' => 'EUR', 'price' => 7000]);
        $group->owner->update(['stripe_connect_account_id' => 'acct_changed']);
        $this->travel(1)->hours();

        $this->assertSame('sub_currency', app(SubscriptionCheckoutService::class)->start($payer, $group, 'pm_changed')['id']);
        $after = DB::table('subscription_attempts')->first();
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->parameters, $after->parameters);
        $this->assertSame($before->started_at, $after->started_at);
        $calls = $lostResponse === 'price' ? $prices : $subscriptions;
        $this->assertSame($calls[0], $calls[1]);
        $this->assertSame('eur', $prices[0][0]['currency']);
        $this->assertSame(1001, $prices[0][0]['unit_amount']);
        $this->assertSame('acct_currency', $subscriptions[0][0]['transfer_data']['destination']);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_attempt_currency_mismatch_blocks_checkout_without_provider_calls_or_new_writes(): void
    {
        $group = $this->group();
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_currency']);
        $attempt = $this->attempt($group, $payer, 'CAD');
        try {
            app(SubscriptionCheckoutService::class)->start($payer, $group, 'pm_changed');
            $this->fail('Mismatched attempt must be rejected.');
        } catch (BillingUnavailable) {
            $this->assertEquals($attempt, DB::table('subscription_attempts')->first());
            $this->assertSame(1, $group->members()->count());
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_existing_eur_subscription_can_resume_with_disabled_flag(): void
    {
        $member = $this->member();
        $this->attempt($member->group, $member->user, 'EUR');
        $this->readSnapshots($this->snapshots());
        $this->actingAs($member->user)->postJson('/api/groups/'.$member->group_id.'/subscribe', ['payment_method_id' => 'pm_changed'])
            ->assertOk()->assertJsonPath('currency', 'EUR');
        $this->assertSame('active', $member->fresh()->status);
        $this->assertDatabaseHas('payments', ['currency' => 'EUR', 'amount' => 333, 'platform_fee_amount' => 17]);
    }

    public function test_conflicting_checkout_response_keeps_attempt_retryable_without_linking_subscription(): void
    {
        config(['payments.eur_enabled' => true]);
        $group = $this->group();
        $payer = User::factory()->create(['stripe_customer_id' => 'cus_currency']);
        $this->gateway->shouldReceive('isAccountActive')->once()->andReturn(true);
        $this->writer->shouldReceive('attachPaymentMethod')->once()->with('pm_currency', 'cus_currency');
        $this->writer->shouldReceive('createPrice')->once()->andReturn('price_currency');
        $this->writer->shouldReceive('createSubscription')->once()->andReturn($this->snapshots('cad')[0]);
        try {
            app(SubscriptionCheckoutService::class)->start($payer, $group, 'pm_currency');
            $this->fail('A contradictory subscription must not be linked.');
        } catch (BillingUnavailable) {
            $member = $group->members()->where('user_id', $payer->id)->firstOrFail();
            $this->assertSame('pending_payment', $member->status);
            $this->assertNull($member->stripe_subscription_id);
            $this->assertDatabaseHas('subscription_attempts', ['group_id' => $group->id, 'price_id' => 'price_currency', 'subscription_id' => null]);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_competing_checkout_cannot_reserve_another_seat_while_group_is_locked(): void
    {
        config(['payments.eur_enabled' => true]);
        $group = $this->group();
        $payer = User::factory()->create();
        $lock = Cache::store('database')->lock('billing:group:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            app(SubscriptionCheckoutService::class)->start($payer, $group, 'pm_currency');
            $this->fail('A competing checkout must wait for the group lock.');
        } catch (BillingUnavailable) {
            $this->assertDatabaseCount('subscription_attempts', 0);
            $this->assertSame(1, $group->fresh()->current_members);
        } finally {
            $lock->release();
        }
    }

    public static function contradictions(): array
    {
        return [
            'all Stripe objects disagree with group' => ['all'],
            'subscription' => ['0.currency'],
            'subscription price' => ['0.items.data.0.price.currency'],
            'expanded invoice' => ['expanded_invoice'],
            'invoice' => ['1.currency'],
            'payment intent' => ['2.currency'],
            'attempt' => ['attempt'],
            'missing subscription currency' => ['missing_subscription'],
            'missing invoice currency' => ['missing_invoice'],
            'unsupported currency' => ['unsupported'],
        ];
    }

    #[DataProvider('contradictions')]
    public function test_currency_contradiction_cannot_activate_or_write_a_payment(string $contradiction): void
    {
        $member = $this->member();
        $snapshots = $this->snapshots();
        if ($contradiction === 'attempt') {
            $this->attempt($member->group, $member->user, 'CAD');
        } elseif ($contradiction === 'all') {
            $snapshots = $this->snapshots('cad');
        } elseif ($contradiction === 'expanded_invoice') {
            $snapshots[0]['latest_invoice'] = ['id' => 'in_currency', 'currency' => 'cad'];
        } elseif ($contradiction === 'missing_subscription') {
            unset($snapshots[0]['currency']);
        } elseif ($contradiction === 'missing_invoice') {
            unset($snapshots[1]['currency']);
        } elseif ($contradiction === 'unsupported') {
            $snapshots = $this->snapshots('usd');
        } else {
            data_set($snapshots, $contradiction, 'cad');
        }
        $this->readSnapshots($snapshots);
        $before = $member->fresh()->getAttributes();
        try {
            app(PaymentSynchronizationService::class)->synchronize('sub_currency');
            $this->fail('Contradictory currency must be rejected.');
        } catch (BillingUnavailable) {
            $this->assertSame($before, $member->fresh()->getAttributes());
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(0, $member->user->fresh()->completed_payments_count);
            Bus::assertNotDispatched(CheckCredentialsProvided::class);
            Mail::assertNothingSent();
        }
    }

    public static function ledgerContradictions(): array
    {
        return [
            'currency matched by invoice' => [['currency' => 'CAD']],
            'currency matched by intent' => [['currency' => 'CAD', 'stripe_invoice_id' => null]],
            'amount' => [['amount' => 334]],
            'different intent on same invoice' => [['stripe_payment_intent_id' => 'pi_other']],
            'different invoice on same intent' => [['stripe_invoice_id' => 'in_other']],
        ];
    }

    #[DataProvider('ledgerContradictions')]
    public function test_existing_ledger_contradictions_preserve_payment_and_membership(array $changes): void
    {
        $member = $this->member();
        $payment = Payment::factory()->create([
            'group_id' => $member->group_id, 'user_id' => $member->user_id, 'amount' => 333, 'currency' => 'EUR',
            'stripe_invoice_id' => 'in_currency', 'stripe_payment_intent_id' => 'pi_currency', ...$changes,
        ]);
        $this->readSnapshots($this->snapshots());
        $before = $payment->fresh()->getAttributes();
        try {
            app(PaymentSynchronizationService::class)->synchronize('sub_currency');
            $this->fail('Existing ledger contradiction must be rejected.');
        } catch (BillingUnavailable) {
            $this->assertSame($before, $payment->fresh()->getAttributes());
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame('pending_payment', $member->fresh()->status);
            $this->assertSame(0, $member->user->fresh()->completed_payments_count);
            Bus::assertNotDispatched(CheckCredentialsProvided::class);
        }
    }

    public function test_invoice_and_intent_matching_two_different_ledger_rows_are_rejected(): void
    {
        $member = $this->member();
        foreach ([['in_currency', 'pi_other'], [null, 'pi_currency']] as [$invoice, $intent]) {
            Payment::factory()->create(['group_id' => $member->group_id, 'user_id' => $member->user_id,
                'currency' => 'EUR', 'amount' => 333, 'stripe_invoice_id' => $invoice, 'stripe_payment_intent_id' => $intent]);
        }
        $before = Payment::orderBy('id')->get()->toArray();
        $this->readSnapshots($this->snapshots());
        try {
            app(PaymentSynchronizationService::class)->synchronize('sub_currency');
            $this->fail('Two different ledger rows cannot represent one invoice settlement.');
        } catch (BillingUnavailable) {
            $this->assertSame($before, Payment::orderBy('id')->get()->toArray());
            $this->assertSame('pending_payment', $member->fresh()->status);
        }
    }

    public function test_signed_eur_webhook_with_currency_mismatch_can_retry_same_event_without_duplicate_payment(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_currency_synthetic', 'services.stripe.connect_webhook_secret' => null]);
        $member = $this->member();
        $snapshots = $this->snapshots();
        $this->reader->shouldReceive('retrieveSubscription')->twice()->with('sub_currency')->andReturn($snapshots[0]);
        $this->reader->shouldReceive('retrieveInvoice')->twice()->with('in_currency')->andReturn([...$snapshots[1], 'currency' => 'cad'], $snapshots[1]);
        $this->reader->shouldReceive('retrievePaymentIntent')->once()->with('pi_currency')->andReturn($snapshots[2]);
        $payload = json_encode([
            'id' => 'evt_currency', 'object' => 'event', 'type' => 'invoice.paid',
            'api_version' => ApiVersion::CURRENT, 'created' => time(), 'livemode' => false,
            'data' => ['object' => [...$snapshots[1], 'object' => 'invoice']],
        ], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', "$time.$payload", 'whsec_currency_synthetic');
        $send = fn () => $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature",
        ], $payload);
        $send()->assertStatus(503);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('pending_payment', $member->fresh()->status);
        $send()->assertOk();
        $send()->assertOk();
        $this->assertSame('active', $member->fresh()->status);
        $this->assertDatabaseHas('payments', ['currency' => 'EUR', 'amount' => 333, 'platform_fee_amount' => 17]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertSame(1, $member->user->fresh()->completed_payments_count);
        Bus::assertDispatched(CheckCredentialsProvided::class, 1);
    }

    #[DataProvider('currencies')]
    public function test_initial_payment_and_renewal_remain_native_idempotent_and_accept_historical_prorata(string $currency): void
    {
        $member = $this->member($currency);
        $this->attempt($member->group, $member->user, $currency);
        $initial = $this->snapshots(strtolower($currency), 'initial', 333);
        $renewal = $this->snapshots(strtolower($currency), 'renewal', 667);
        $this->reader->shouldReceive('retrieveSubscription')->times(4)->with('sub_currency')->andReturn($renewal[0]);
        foreach ([$initial, $renewal] as [, $invoice, $intent]) {
            $this->reader->shouldReceive('retrieveInvoice')->twice()->with($invoice['id'])->andReturn($invoice);
            $this->reader->shouldReceive('retrievePaymentIntent')->twice()->with($intent['id'])->andReturn($intent);
        }
        $service = app(PaymentSynchronizationService::class);
        $first = $service->synchronize('sub_currency', 'in_initial');
        $this->assertSame('pending_payment', $member->fresh()->status);
        $next = $service->synchronize('sub_currency');
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame($first->id, $service->synchronize('sub_currency', 'in_initial')->id);
        $this->assertSame($next->id, $service->synchronize('sub_currency')->id);
        $this->assertSame($currency, $first->currency);
        $this->assertSame($currency, $next->currency);
        $this->assertSame(17, $first->platform_fee_amount);
        $this->assertSame(33, $next->platform_fee_amount);
        $this->assertSame(2, $member->user->fresh()->completed_payments_count);
        $this->assertDatabaseCount('payments', 2);
        Bus::assertDispatched(CheckCredentialsProvided::class, 2);
    }

    #[DataProvider('currencies')]
    public function test_prorata_and_success_response_use_group_currency_and_existing_rounding(string $currency): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->startOfDay());
        $member = $this->member($currency);
        $this->actingAs($member->user)->getJson('/api/groups/'.$member->group_id.'/proration')
            ->assertOk()->assertJsonPath('currency', $currency)->assertJsonPath('amount_recurring', 1001)
            ->assertJsonPath('amount_today', 807)->assertJsonPath('days_remaining', 25);
        $this->get('/payment/success?group_id='.$member->group_id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('PaymentSuccess')
                ->where('group.currency', $currency)->where('group.pricePerMember', 1001)->where('credentials', null));
    }

    public function test_recalculation_keeps_eur_with_flag_disabled_after_membership_changes(): void
    {
        $member = $this->member();
        $member->update(['status' => 'active']);
        $group = $member->group;
        $additional = GroupMember::factory()->create(['group_id' => $group->id]);
        $this->gateway->shouldReceive('createMonthlyPrice')->once()->withArgs(fn (Group $received, int $amount) => $received->id === $group->id && $received->currency === 'EUR' && $amount === 667)->andReturn('price_join');
        $this->gateway->shouldReceive('updateSubscriptionItemPrice')->once()->with('si_currency', 'price_join');
        (new RecalculateGroupPrices)->handle($this->gateway);
        $this->assertSame(667, $member->fresh()->share_amount);
        $additional->update(['status' => 'left']);
        $this->gateway->shouldReceive('createMonthlyPrice')->once()->withArgs(fn (Group $received, int $amount) => $received->currency === 'EUR' && $amount === 1001)->andReturn('price_leave');
        $this->gateway->shouldReceive('updateSubscriptionItemPrice')->once()->with('si_currency', 'price_leave');
        (new RecalculateGroupPrices)->handle($this->gateway);
        $this->assertSame(1001, $member->fresh()->share_amount);
    }

    public function test_eur_refund_replay_keeps_original_payment_and_currency_with_flag_disabled(): void
    {
        $member = $this->member();
        $member->update(['status' => 'active', 'subscription_status' => 'active']);
        $payment = Payment::factory()->create(['group_id' => $member->group_id, 'user_id' => $member->user_id,
            'currency' => 'EUR', 'amount' => 333, 'stripe_payment_intent_id' => 'pi_currency']);
        $this->gateway->shouldReceive('cancelSubscription')->once()->with('sub_currency');
        $this->gateway->shouldReceive('refundPayment')->once()->withArgs(function (string $intent, ?int $amount, string $key) use ($payment) {
            return $intent === 'pi_currency' && $amount === null
                && $key === DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->value('idempotency_key');
        })->andReturn(['refund_id' => 're_currency', 'status' => 'succeeded', 'amount' => 333]);
        $service = app(PaymentService::class);
        $service->refundPayment($payment, 'dispute_resolved');
        $result = $service->refundPayment($payment, 'dispute_resolved');
        $this->assertSame('EUR', $result->currency);
        $this->assertSame(333, $result->amount);
        $this->assertSame('refunded', $result->status);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(1, $member->group->fresh()->current_members);
        $this->assertDatabaseCount('payment_refund_attempts', 1);
    }

    public function test_external_refund_in_another_currency_cannot_change_the_ledger_or_release_access(): void
    {
        $member = $this->member();
        $member->update(['status' => 'active', 'subscription_status' => 'active']);
        $payment = Payment::factory()->create(['group_id' => $member->group_id, 'user_id' => $member->user_id,
            'currency' => 'EUR', 'amount' => 333, 'stripe_payment_intent_id' => 'pi_currency']);
        $this->reader->shouldReceive('retrievePaymentIntent')->once()->with('pi_currency')->andReturn([
            'id' => 'pi_currency', 'currency' => 'cad',
            'latest_charge' => ['amount_refunded' => 333, 'refunded' => true],
        ]);

        try {
            app(PaymentRefundService::class)->synchronizeIntent('pi_currency');
            $this->fail('A refund in another currency must require reconciliation.');
        } catch (BillingUnavailable) {
            $this->assertSame('completed', $payment->fresh()->status);
            $this->assertSame('EUR', $payment->fresh()->currency);
            $this->assertSame('active', $member->fresh()->status);
            $this->assertSame(2, $member->group->fresh()->current_members);
            $this->assertDatabaseCount('payment_refund_attempts', 0);
        }
    }
}
