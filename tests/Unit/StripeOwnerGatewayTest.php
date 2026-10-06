<?php

namespace Tests\Unit;

use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Features\Payment\Services\OwnerOnboardingException;
use App\Features\Payment\Services\StripeOwnerGateway;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

class StripeOwnerGatewayTest extends TestCase
{
    private StripeOwnerGateway $gateway;

    private ClientInterface $transport;

    private array $inspections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = Mockery::mock(ClientInterface::class);
        ApiRequestor::setHttpClient($this->transport);
        $this->gateway = new StripeOwnerGateway(new StripeClient('sk_test_owner_dummy_no_network'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();
        foreach ($this->inspections as $inspect) {
            $inspect();
        }
        parent::tearDown();
    }

    private function response(string $method, string $path, array $result, ?\Closure $inspect = null): void
    {
        $this->transport->shouldReceive('request')->once()->withArgs(function ($verb, $url, $headers, $params) use ($method, $path, $inspect): bool {
            $this->assertSame($method, $verb);
            $this->assertSame('https://api.stripe.com'.$path, $url);
            $this->assertContains('Stripe-Version: '.ApiVersion::CURRENT, $headers);
            if ($inspect) {
                $this->inspections[] = fn () => $inspect($params, $headers);
            }

            return true;
        })->andReturn([json_encode($result, JSON_THROW_ON_ERROR), 200, []]);
    }

    public function test_sdk_forwards_persistent_idempotency_key_without_overriding_api_version(): void
    {
        $parameters = ['type' => 'express', 'country' => 'CA', 'email' => 'owner@example.test',
            'business_type' => 'individual',
            'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]]];
        $this->response('post', '/v1/accounts', ['id' => 'acct_created', 'object' => 'account'], function ($params, $headers) use ($parameters) {
            $parameters['capabilities']['card_payments']['requested'] = 'true';
            $parameters['capabilities']['transfers']['requested'] = 'true';
            $this->assertSame($parameters, $params);
            $this->assertContains('Idempotency-Key: owner-durable-dummy', $headers);
        });
        $this->assertSame('acct_created', $this->gateway->createAccount($parameters, 'owner-durable-dummy'));
    }

    public function test_link_uses_account_onboarding_and_supplied_internal_callbacks(): void
    {
        $this->response('post', '/v1/account_links', ['object' => 'account_link', 'url' => 'https://connect.stripe.com/dummy'], function ($params) {
            $this->assertSame([
                'account' => 'acct_existing', 'return_url' => 'https://equitab.example/stripe/onboarding/return',
                'refresh_url' => 'https://equitab.example/stripe/onboarding/refresh', 'type' => 'account_onboarding',
            ], $params);
        });
        $this->assertSame('https://connect.stripe.com/dummy', $this->gateway->createAccountLink(
            'acct_existing', 'https://equitab.example/stripe/onboarding/return', 'https://equitab.example/stripe/onboarding/refresh',
        ));
    }

    public function test_identity_keeps_document_and_matching_selfie_without_invented_personal_data(): void
    {
        $this->response('post', '/v1/identity/verification_sessions', [
            'object' => 'identity.verification_session', 'id' => 'vs_created', 'status' => 'requires_input', 'url' => 'https://verify.stripe.com/dummy',
        ], function ($params) {
            $this->assertSame([
                'type' => 'document', 'metadata' => ['user_id' => '42'],
                'options' => ['document' => ['require_matching_selfie' => 'true']],
                'return_url' => 'https://equitab.example/dashboard/profile',
            ], $params);
        });
        $result = $this->gateway->createIdentity('42', 'https://equitab.example/dashboard/profile');
        $this->assertSame('vs_created', $result->id);
        $this->assertSame('unverified', $result->status());
    }

    public function test_account_sdk_response_projects_pending_requirements(): void
    {
        $this->response('get', '/v1/accounts/acct_owner', [
            'object' => 'account', 'id' => 'acct_owner', 'charges_enabled' => true,
            'payouts_enabled' => true, 'details_submitted' => true,
            'requirements' => ['pending_verification' => ['individual.verification.document'], 'past_due' => [], 'disabled_reason' => null],
        ]);
        $this->assertSame('pending', $this->gateway->retrieveAccount('acct_owner')->status());
    }

    public function test_identity_retrieval_projects_verified(): void
    {
        $this->response('get', '/v1/identity/verification_sessions/vs_owner', [
            'object' => 'identity.verification_session', 'id' => 'vs_owner', 'status' => 'verified', 'url' => null,
        ]);
        $this->assertSame('verified', $this->gateway->retrieveIdentity('vs_owner')->status());
    }

    public function test_incomplete_account_response_never_becomes_active(): void
    {
        $this->response('get', '/v1/accounts/acct_owner', [
            'object' => 'account', 'id' => 'acct_owner', 'charges_enabled' => true,
        ]);
        $this->expectException(OwnerOnboardingException::class);
        $this->gateway->retrieveAccount('acct_owner');
    }

    public function test_wrong_account_id_response_is_rejected(): void
    {
        $this->response('get', '/v1/accounts/acct_owner', [
            'object' => 'account', 'id' => 'acct_someone_else', 'charges_enabled' => true, 'payouts_enabled' => true,
        ]);
        $this->expectException(OwnerOnboardingException::class);
        $this->gateway->retrieveAccount('acct_owner');
    }

    #[DataProvider('gatewayOperations')]
    public function test_sdk_errors_are_typed_and_do_not_expose_details(string $operation): void
    {
        $this->transport->shouldReceive('request')->once()->andThrow(new \RuntimeException('private response or secret'));
        try {
            match ($operation) {
                'account' => $this->gateway->createAccount([], 'dummy-key'),
                'link' => $this->gateway->createAccountLink('acct_owner', 'https://example.test/return', 'https://example.test/refresh'),
                'read_account' => $this->gateway->retrieveAccount('acct_owner'),
                'identity' => $this->gateway->createIdentity('42', 'https://example.test/return'),
                'read_identity' => $this->gateway->retrieveIdentity('vs_owner'),
            };
            $this->fail('An SDK error must not produce success.');
        } catch (OwnerOnboardingException $e) {
            $this->assertStringNotContainsString('private', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public static function gatewayOperations(): array
    {
        return array_map(fn ($operation) => [$operation], ['account', 'link', 'read_account', 'identity', 'read_identity']);
    }

    #[DataProvider('connectStates')]
    public function test_status_projection_requires_both_capabilities_and_no_pending_restrictions(bool $charges, bool $payouts, bool $details, bool $pending, ?string $disabled, bool $pastDue, string $expected): void
    {
        $this->assertSame($expected, (new OwnerConnectState('acct_owner', $charges, $payouts, $details, $pending, $disabled, $pastDue))->status());
    }

    public static function connectStates(): array
    {
        return [
            'active' => [true, true, true, false, null, false, 'active'],
            'charges only' => [true, false, true, false, null, false, 'pending'],
            'payouts only' => [false, true, true, false, null, false, 'pending'],
            'pending verification' => [true, true, true, true, null, false, 'pending'],
            'pending reason' => [true, true, true, false, 'requirements.pending_verification', false, 'pending'],
            'rejected' => [true, true, true, false, 'rejected.other', false, 'restricted'],
            'past due' => [true, true, true, true, null, true, 'restricted'],
            'abandoned' => [false, false, false, false, null, false, 'restricted'],
        ];
    }

    #[DataProvider('identityStates')]
    public function test_identity_projection(string $stripeStatus, string $expected): void
    {
        $this->assertSame($expected, (new OwnerIdentityState('vs_owner', $stripeStatus))->status());
    }

    public static function identityStates(): array
    {
        return [['verified', 'verified'], ['processing', 'pending'], ['requires_input', 'unverified'], ['canceled', 'unverified']];
    }

    public function test_unknown_identity_state_fails_closed(): void
    {
        $this->expectException(OwnerOnboardingException::class);
        (new OwnerIdentityState('vs_owner', 'unknown'))->status();
    }
}
