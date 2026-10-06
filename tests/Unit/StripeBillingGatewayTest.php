<?php

namespace Tests\Unit;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Services\BillingUnavailable;
use App\Features\Payment\Services\StripeBillingGateway;
use App\Features\Payment\Services\StripeGateway;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

class StripeBillingGatewayTest extends TestCase
{
    private BillingHttpFake $http;

    private ClientInterface $original;

    private StripeBillingGateway $billing;

    private StripeGateway $payments;

    protected function setUp(): void
    {
        $this->original = ApiRequestor::httpClient();
        $this->http = new BillingHttpFake;
        ApiRequestor::setHttpClient($this->http);
        $client = new StripeClient('sk_test_offline_billing');
        $this->billing = new StripeBillingGateway($client);
        $this->payments = new StripeGateway($client, $this->createStub(OwnerStripeGatewayInterface::class));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->original);
        parent::tearDown();
    }

    public function test_refund_reverses_destination_transfer_and_has_the_durable_idempotency_key(): void
    {
        $this->http->responses = [['id' => 're_test', 'object' => 'refund', 'status' => 'pending', 'amount' => 1000]];
        self::assertSame(['refund_id' => 're_test', 'status' => 'pending', 'amount' => 1000], $this->payments->refundPayment('pi_test', null, 'attempt-key'));
        $request = $this->http->requests[0];
        self::assertSame('post', $request['method']);
        self::assertSame('https://api.stripe.com/v1/refunds', $request['url']);
        self::assertSame(['payment_intent' => 'pi_test', 'reverse_transfer' => 'true'], $request['params']);
        self::assertArrayNotHasKey('refund_application_fee', $request['params']);
        self::assertContains('Idempotency-Key: attempt-key', $request['headers']);
    }

    public function test_repeated_fallback_refund_uses_the_same_key_and_partial_amount(): void
    {
        $refund = ['id' => 're_test', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 100];
        $this->http->responses = [$refund, $refund];
        $this->payments->refundPayment('pi_test', 100);
        $this->payments->refundPayment('pi_test', 100);
        $key = 'Idempotency-Key: '.hash('sha256', 'refund:pi_test:100');
        foreach ($this->http->requests as $request) {
            self::assertContains($key, $request['headers']);
            self::assertSame(100, $request['params']['amount']);
            self::assertSame('true', $request['params']['reverse_transfer']);
        }
    }

    public function test_already_canceled_subscription_does_not_require_another_delete(): void
    {
        $this->http->responses = [['id' => 'sub_test', 'object' => 'subscription', 'status' => 'canceled']];
        $this->payments->cancelSubscription('sub_test');
        self::assertCount(1, $this->http->requests);
        self::assertSame('get', $this->http->requests[0]['method']);
    }

    public function test_cancel_requires_a_confirmed_terminal_provider_state(): void
    {
        $sub = ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'active'];
        $this->http->responses = [$sub, $sub];
        try {
            $this->payments->cancelSubscription('sub_test');
            self::fail('Unconfirmed cancellation');
        } catch (BillingUnavailable) {
            self::assertSame(['get', 'delete'], array_column($this->http->requests, 'method'));
        }
    }

    public function test_subscription_reader_uses_modern_confirmation_secret_expansion(): void
    {
        $this->http->responses = [['id' => 'sub_test', 'object' => 'subscription', 'latest_invoice' => ['id' => 'in_test', 'object' => 'invoice', 'confirmation_secret' => ['client_secret' => 'private']]]];
        $result = $this->billing->retrieveSubscription('sub_test');
        self::assertSame('private', $result['latest_invoice']['confirmation_secret']['client_secret']);
        self::assertSame(['expand' => ['latest_invoice.confirmation_secret']], $this->http->requests[0]['params']);
    }

    public function test_invoice_payments_are_exhaustively_paginated_without_old_invoice_payment_intent_field(): void
    {
        $one = ['id' => 'inpay_one', 'object' => 'invoice_payment', 'invoice' => 'in_test', 'status' => 'paid'];
        $two = [...$one, 'id' => 'inpay_two'];
        $this->http->responses = [
            ['object' => 'list', 'url' => '/v1/invoice_payments', 'has_more' => true, 'data' => [$one]],
            ['object' => 'list', 'url' => '/v1/invoice_payments', 'has_more' => false, 'data' => [$two]],
        ];
        self::assertSame([$one, $two], $this->billing->invoicePayments('in_test'));
        self::assertSame(['invoice' => 'in_test', 'status' => 'paid', 'limit' => 100], $this->http->requests[0]['params']);
        self::assertSame('inpay_one', $this->http->requests[1]['params']['starting_after']);
    }

    public function test_customer_price_and_subscription_writes_forward_persisted_keys(): void
    {
        $this->http->responses = [['id' => 'cus_test', 'object' => 'customer'], ['id' => 'price_test', 'object' => 'price'], ['id' => 'sub_test', 'object' => 'subscription']];
        self::assertSame('cus_test', $this->billing->createCustomer(['name' => 'Test'], 'customer-key'));
        self::assertSame('price_test', $this->billing->createPrice(['unit_amount' => 1000], 'price-key'));
        self::assertSame('sub_test', $this->billing->createSubscription(['customer' => 'cus_test'], 'subscription-key')['id']);
        foreach (['customer-key', 'price-key', 'subscription-key'] as $index => $key) {
            self::assertContains('Idempotency-Key: '.$key, $this->http->requests[$index]['headers']);
        }
    }

    public function test_existing_own_payment_method_is_reused_but_foreign_one_is_rejected(): void
    {
        $this->http->responses = [['id' => 'pm_test', 'object' => 'payment_method', 'customer' => 'cus_test'], ['id' => 'pm_foreign', 'object' => 'payment_method', 'customer' => 'cus_other']];
        $this->billing->attachPaymentMethod('pm_test', 'cus_test');
        self::assertCount(1, $this->http->requests);
        $this->expectException(BillingUnavailable::class);
        $this->billing->attachPaymentMethod('pm_foreign', 'cus_test');
    }
}

final class BillingHttpFake implements ClientInterface
{
    public array $responses = [];

    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('Unexpected offline billing request');
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }
}
