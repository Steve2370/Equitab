<?php

namespace Tests\Unit;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Services\StripeGateway;
use App\Models\Group;
use App\Models\StripePrice;
use App\Models\Subscription;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

class CurrencyStripeGatewayTest extends TestCase
{
    private CurrencyStripeHttpFake $http;

    private ClientInterface $original;

    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = ApiRequestor::httpClient();
        $this->http = new CurrencyStripeHttpFake;
        ApiRequestor::setHttpClient($this->http);
        $this->gateway = new StripeGateway(new StripeClient('sk_test_offline_currency'), $this->createStub(OwnerStripeGatewayInterface::class));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->original);
        parent::tearDown();
    }

    public static function currencies(): array
    {
        return [['CAD', 'EUR', 'cad'], ['EUR', 'CAD', 'eur']];
    }

    #[DataProvider('currencies')]
    public function test_monthly_price_uses_group_currency_and_preserves_amount_and_recurrence(string $currency, string $catalogCurrency, string $stripeCurrency): void
    {
        $group = new Group;
        $group->setRawAttributes(['currency' => $currency]);
        $group->setRelation('subscription', new Subscription(['currency' => $catalogCurrency]));
        $group->setRelation('stripePrice', new StripePrice(['stripe_product_id' => 'prod_currency']));
        $this->http->responses[] = ['id' => 'price_currency', 'object' => 'price'];

        self::assertSame('price_currency', $this->gateway->createMonthlyPrice($group, 667));
        self::assertSame('https://api.stripe.com/v1/prices', $this->http->requests[0]['url']);
        self::assertSame([
            'unit_amount' => 667, 'currency' => $stripeCurrency,
            'recurring' => ['interval' => 'month'], 'product' => 'prod_currency',
        ], $this->http->requests[0]['params']);
    }

    public function test_unknown_group_currency_cannot_create_a_price(): void
    {
        $group = new Group;
        $group->setRawAttributes(['currency' => 'USD']);
        try {
            $this->gateway->createMonthlyPrice($group, 1000);
            self::fail('Unknown currency must not reach Stripe.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function test_price_replacement_preserves_no_proration_policy(): void
    {
        $this->http->responses[] = ['id' => 'si_currency', 'object' => 'subscription_item'];
        $this->gateway->updateSubscriptionItemPrice('si_currency', 'price_eur');
        self::assertSame(['price' => 'price_eur', 'proration_behavior' => 'none'], $this->http->requests[0]['params']);
    }

    public function test_eur_partial_refund_replays_original_intent_amount_and_key_without_currency_override(): void
    {
        $refund = ['id' => 're_currency', 'object' => 'refund', 'currency' => 'eur', 'amount' => 333, 'status' => 'succeeded'];
        $this->http->responses = [$refund, $refund];
        for ($i = 0; $i < 2; $i++) {
            self::assertSame(['refund_id' => 're_currency', 'status' => 'succeeded', 'amount' => 333], $this->gateway->refundPayment('pi_eur', 333, 'durable-eur-refund'));
        }
        self::assertCount(2, $this->http->requests);
        foreach ($this->http->requests as $request) {
            self::assertSame('https://api.stripe.com/v1/refunds', $request['url']);
            self::assertSame(['payment_intent' => 'pi_eur', 'reverse_transfer' => 'true', 'amount' => 333], $request['params']);
            self::assertContains('Idempotency-Key: durable-eur-refund', $request['headers']);
        }
    }
}

final class CurrencyStripeHttpFake implements ClientInterface
{
    public array $responses = [];

    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('Unexpected offline currency request.');
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }
}
