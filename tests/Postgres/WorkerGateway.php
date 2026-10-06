<?php

namespace Tests\Postgres;

use App\Features\Group\Contracts\GroupProductGateway;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Features\Payment\Services\OwnerOnboardingException;
use Illuminate\Support\Facades\DB;

/** Only external Stripe effects are doubled; domain services and SQL are real. */
final class WorkerGateway implements GroupProductGateway, OwnerStripeGatewayInterface
{
    public function __construct(private readonly string $worker, private readonly array $options) {}

    private function boundary(string $point): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('An external call is holding a SQL transaction.');
        }
        DB::table('qa_signals')->insertOrIgnore(['key' => $point.':'.$this->worker]);
        if (($this->options['pause'] ?? null) !== $point) {
            return;
        }
        $deadline = microtime(true) + 12;
        while (! DB::table('qa_signals')->where('key', 'release:'.$this->worker)->exists()) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for the test coordinator.');
            }
            usleep(20000);
        }
    }

    public function ensureProduct(string $draftId, string $name, int $ownerId, int $version): string
    {
        $id = 'prod_pg_'.$draftId.'_v'.$version;
        DB::table('qa_products')->insertOrIgnore(['id' => $id]);
        $this->boundary('product');

        return $id;
    }

    public function createAccount(array $parameters, string $idempotencyKey): string
    {
        $id = 'acct_pg_'.str_replace('-', '', $idempotencyKey);
        DB::table('qa_accounts')->insertOrIgnore([
            'key' => $idempotencyKey, 'id' => $id,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
        ]);
        $existing = DB::table('qa_accounts')->where('key', $idempotencyKey)->first();
        if (json_decode($existing->parameters, true, flags: JSON_THROW_ON_ERROR) !== $parameters) {
            throw new \LogicException('Idempotency parameters changed between retries.');
        }
        $this->boundary('account');
        if ($this->options['lost_response'] ?? false) {
            throw new OwnerOnboardingException;
        }

        return $existing->id;
    }

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return $returnUrl;
    }

    public function retrieveAccount(string $accountId): OwnerConnectState
    {
        $row = DB::table('qa_remote_accounts')->where('id', $accountId)->first();
        $active = $row === null || $row->active;
        $this->boundary('read_account');

        return new OwnerConnectState($accountId, $active, $active, true,
            disabledReason: $active ? null : 'requirements.past_due');
    }

    public function retrieveIdentity(string $sessionId): OwnerIdentityState
    {
        $this->boundary('read_identity');

        return new OwnerIdentityState($sessionId, 'verified');
    }

    public function createIdentity(string $userId, string $returnUrl): OwnerIdentityState
    {
        throw new \LogicException('Identity creation is not allowed by this test double.');
    }
}
