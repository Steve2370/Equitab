<?php

namespace Tests\Unit;

use App\Features\Group\Exceptions\PublicationUnavailable;
use App\Features\Group\Services\StripeGroupProductGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class StripeGroupProductGatewayTest extends TestCase
{
    private ProductHttpFake $http;

    private StripeGroupProductGateway $gateway;

    private string $draft = '68fd9f37-4afc-43d7-91dd-31054e0ec341';

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new ProductHttpFake;
        ApiRequestor::setHttpClient($this->http);
        $this->gateway = new StripeGroupProductGateway(new StripeClient('sk_test_offline_products'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient);
        parent::tearDown();
    }

    private function product(int $version = 1): array
    {
        return [
            'id' => 'equitab_draft_'.str_replace('-', '', $this->draft).'_v'.$version,
            'object' => 'product', 'active' => true,
            'metadata' => ['draft_id' => $this->draft, 'owner_id' => '7', 'draft_version' => (string) $version],
        ];
    }

    private function missing(): array
    {
        return [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'Missing product']], 404];
    }

    public function test_existing_product_is_reused_without_a_create_even_beyond_idempotency_window(): void
    {
        $this->http->responses = [[$this->product(), 200]];
        self::assertSame($this->product()['id'], $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1));
        self::assertCount(1, $this->http->requests);
        self::assertSame('get', $this->http->requests[0]['method']);
    }

    public function test_creation_uses_a_permanent_id_and_a_versioned_idempotency_key(): void
    {
        $this->http->responses = [$this->missing(), [$this->product(2), 200]];
        self::assertSame($this->product(2)['id'], $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 2));
        $request = $this->http->requests[1];
        self::assertSame('post', $request['method']);
        self::assertSame($this->product(2)['id'], $request['params']['id']);
        self::assertSame('Mon groupe — EquitAb', $request['params']['name']);
        self::assertSame($this->product(2)['metadata'], $request['params']['metadata']);
        self::assertContains('Idempotency-Key: group-draft-product:'.$this->draft.':2', $request['headers']);
    }

    public function test_a_lost_creation_response_is_recovered_by_the_permanent_product_id(): void
    {
        $this->http->responses = [$this->missing(), new ApiConnectionException('Synthetic lost response'), [$this->product(), 200]];
        self::assertSame($this->product()['id'], $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1));
        self::assertSame(['get', 'post', 'get'], array_column($this->http->requests, 'method'));
    }

    public function test_a_concurrent_creation_response_is_recovered_without_a_second_product(): void
    {
        $this->http->responses = [$this->missing(), [
            ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_already_exists', 'message' => 'Already exists']], 400,
        ], [$this->product(), 200]];
        self::assertSame($this->product()['id'], $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1));
        self::assertCount(3, $this->http->requests);
    }

    public function test_a_product_belonging_to_someone_else_cannot_be_claimed(): void
    {
        $product = $this->product();
        $product['metadata']['owner_id'] = '99';
        $this->http->responses = [[$product, 200]];
        $this->expectException(PublicationUnavailable::class);
        $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1);
    }

    public function test_a_previous_version_cannot_be_claimed_after_reopening(): void
    {
        $this->http->responses = [[$this->product(1), 200]];
        $this->expectException(PublicationUnavailable::class);
        $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 2);
    }

    public function test_archived_products_are_not_published(): void
    {
        $product = $this->product();
        $product['active'] = false;
        $this->http->responses = [[$product, 200]];
        $this->expectException(PublicationUnavailable::class);
        $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1);
    }

    #[DataProvider('incompleteProducts')]
    public function test_missing_or_deleted_product_fields_fail_closed_without_output(array $fields): void
    {
        $this->http->responses = [[['id' => $this->product()['id'], 'object' => 'product', ...$fields], 200]];
        $this->expectOutputString('');
        $this->expectException(PublicationUnavailable::class);
        $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1);
    }

    public static function incompleteProducts(): array
    {
        return [
            'missing fields' => [[]],
            'deleted' => [['deleted' => true]],
            'missing ownership' => [['active' => true, 'metadata' => []]],
        ];
    }

    public function test_a_stripe_outage_has_a_safe_error_and_never_creates_blindly(): void
    {
        $this->http->responses = [[['error' => ['type' => 'api_error', 'message' => 'Synthetic sensitive upstream details']], 500]];
        try {
            $this->gateway->ensureProduct($this->draft, 'Mon groupe', 7, 1);
            self::fail('Expected failure');
        } catch (PublicationUnavailable $e) {
            self::assertStringNotContainsString('sensitive', $e->getMessage());
            self::assertCount(1, $this->http->requests);
        }
    }
}

/** Offline transport: every unexpected HTTP request fails instead of reaching Stripe. */
class ProductHttpFake implements ClientInterface
{
    public array $responses = [];

    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response === null) {
            throw new \LogicException('Unexpected offline Stripe request');
        }

        return [json_encode($response[0], JSON_THROW_ON_ERROR), $response[1], []];
    }
}
