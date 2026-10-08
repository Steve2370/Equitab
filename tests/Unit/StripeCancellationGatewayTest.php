<?php

namespace Tests\Unit;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Services\BillingUnavailable;
use App\Features\Payment\Services\StripeGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

class StripeCancellationGatewayTest extends TestCase
{
    private CancellationStripeHttpFake $http;

    private ClientInterface $original;

    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = ApiRequestor::httpClient();
        $this->http = new CancellationStripeHttpFake;
        ApiRequestor::setHttpClient($this->http);
        $this->gateway = new StripeGateway(new StripeClient('sk_test_offline_cancellation'), $this->createStub(OwnerStripeGatewayInterface::class));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->original);
        parent::tearDown();
    }

    public static function terminalStatuses(): array
    {
        return [['canceled'], ['incomplete_expired']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_already_terminal_subscription_needs_no_remote_mutation(string $status): void
    {
        $this->http->statuses = [$status];
        $this->gateway->cancelSubscription('sub_offline_cancel');
        self::assertSame(['get'], $this->http->methods);
    }

    #[DataProvider('terminalStatuses')]
    public function test_cancellation_accepts_only_confirmed_terminal_status(string $status): void
    {
        $this->http->statuses = ['incomplete', $status];
        $this->gateway->cancelSubscription('sub_offline_cancel');
        self::assertSame(['get', 'delete'], $this->http->methods);
    }

    public static function nonTerminalStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['incomplete', 'trialing', 'active', 'past_due', 'unpaid', 'paused', 'unknown']);
    }

    #[DataProvider('nonTerminalStatuses')]
    public function test_non_terminal_response_is_not_reported_as_cancelled(string $status): void
    {
        $this->http->statuses = ['active', $status];
        $this->expectException(BillingUnavailable::class);
        $this->gateway->cancelSubscription('sub_offline_cancel');
    }
}

final class CancellationStripeHttpFake implements ClientInterface
{
    public array $statuses = [];

    public array $methods = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->methods[] = $method;
        $status = array_shift($this->statuses);
        if ($status === null || $absUrl !== 'https://api.stripe.com/v1/subscriptions/sub_offline_cancel') {
            throw new \LogicException('Unexpected offline cancellation request.');
        }

        return [json_encode(['id' => 'sub_offline_cancel', 'object' => 'subscription', 'status' => $status], JSON_THROW_ON_ERROR), 200, []];
    }
}
