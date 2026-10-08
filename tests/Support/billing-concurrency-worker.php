<?php

require dirname(__DIR__).'/Postgres/bootstrap.php';

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingUnavailable;
use App\Features\Payment\Services\PaymentService;
use App\Features\Payment\Services\SubscriptionCancellationService;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Postgres\PostgresEnvironment;

/** Stateful fake provider shared by independent PHP processes, never a domain fake. */
final class BillingConcurrencyGateway implements BillingReadGatewayInterface, CheckoutGatewayInterface
{
    public function __construct(private readonly string $worker, private readonly array $options) {}

    private function observe(string $operation, array $parameters, ?string $key = null): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('A provider call holds a SQL transaction.');
        }
        DB::table('qa_billing_calls')->insert([
            'worker' => $this->worker, 'operation' => $operation, 'idempotency_key' => $key,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
        ]);
    }

    private function boundary(string $point): void
    {
        DB::table('qa_signals')->insertOrIgnore(['key' => $point.':'.$this->worker]);
        if (($this->options['pause'] ?? null) === $point) {
            $deadline = microtime(true) + 12;
            while (! DB::table('qa_signals')->where('key', 'release:'.$this->worker)->exists()) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for billing test coordinator.');
                }
                usleep(20000);
            }
        }
        if (($this->options['lost_response'] ?? null) === $point) {
            throw new BillingUnavailable;
        }
    }

    private function create(string $operation, string $prefix, array $parameters, string $key, array $state): array
    {
        if ($key === '') {
            throw new LogicException('An idempotency key is required by this scenario.');
        }
        $this->observe($operation, $parameters, $key);
        $id = $prefix.'_pg_'.substr(hash('sha256', $operation.':'.$key), 0, 28);
        DB::table('qa_billing_remote')->insertOrIgnore([
            'key' => $operation.':'.$key, 'remote_id' => $id, 'operation' => $operation,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'state' => json_encode(['id' => $id, ...$state], JSON_THROW_ON_ERROR),
        ]);
        $row = DB::table('qa_billing_remote')->where('key', $operation.':'.$key)->sole();
        if (json_decode($row->parameters, true, flags: JSON_THROW_ON_ERROR) !== $parameters) {
            throw new LogicException('Provider idempotency parameters changed on retry.');
        }
        $this->boundary($operation);

        return json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
    }

    private function read(string $operation, string $id): array
    {
        $this->observe($operation, ['id' => $id]);
        $row = DB::table('qa_billing_remote')->where('remote_id', $id)->sole();
        $state = json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
        $this->boundary($operation);

        return $state;
    }

    public function createCustomer(array $parameters, string $key): string
    {
        return $this->create('customer', 'cus', $parameters, $key, ['object' => 'customer'])['id'];
    }

    public function attachPaymentMethod(string $methodId, string $customerId): void
    {
        $this->observe('attach', ['method' => $methodId, 'customer' => $customerId]);
        if (! str_starts_with($methodId, 'pm_') || ! DB::table('qa_billing_remote')->where('remote_id', $customerId)->exists()) {
            throw new LogicException('Invalid synthetic customer or payment method.');
        }
        $this->boundary('attach');
    }

    public function createPrice(array $parameters, string $key): string
    {
        if (! is_int($parameters['unit_amount'] ?? null) || $parameters['unit_amount'] < 0
            || ! in_array($parameters['currency'] ?? null, ['cad', 'eur'], true) || ! str_starts_with($parameters['product'] ?? '', 'prod_')) {
            throw new LogicException('Unexpected synthetic price parameters.');
        }

        return $this->create('price', 'price', $parameters, $key, ['object' => 'price', ...$parameters])['id'];
    }

    public function createSubscription(array $parameters, string $key): array
    {
        if (! DB::table('qa_billing_remote')->where('remote_id', $parameters['customer'])->exists()
            || ! DB::table('qa_billing_remote')->where('remote_id', $parameters['items'][0]['price'])->exists()) {
            throw new LogicException('Subscription references an unknown customer or price.');
        }
        $price = DB::table('qa_billing_remote')->where('remote_id', $parameters['items'][0]['price'])->sole();
        $priceState = json_decode($price->state, true, flags: JSON_THROW_ON_ERROR);
        if ($price->operation !== 'price' || ! in_array($priceState['currency'] ?? null, ['cad', 'eur'], true)) {
            throw new LogicException('Subscription references an invalid synthetic price.');
        }

        return $this->create('subscription', 'sub', $parameters, $key, [
            'object' => 'subscription', 'customer' => $parameters['customer'], 'status' => 'incomplete',
            'currency' => $priceState['currency'], 'latest_invoice' => null,
            'items' => ['data' => [['id' => 'si_pg_'.substr(hash('sha256', $key), 0, 20), 'current_period_end' => time() + 86400 * 30]]],
        ]);
    }

    public function retrieveSubscription(string $id): array
    {
        return $this->read('read_subscription', $id);
    }

    public function retrieveInvoice(string $id): array
    {
        return $this->read('read_invoice', $id);
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->read('read_intent', $id);
    }

    public function retrieveRefund(string $id): array
    {
        return $this->read('read_refund', $id);
    }

    public function invoicePayments(string $invoiceId): array
    {
        $this->observe('invoice_payments', ['invoice' => $invoiceId]);
        $payments = DB::table('qa_billing_remote')->where('operation', 'invoice_payment')->get()
            ->map(fn ($row) => json_decode($row->state, true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn ($payment) => ($payment['invoice'] ?? null) === $invoiceId)->values()->all();
        $this->boundary('invoice_payments');

        return $payments;
    }

    public function isAccountActive(User $owner): bool
    {
        $this->observe('account', ['id' => $owner->stripe_connect_account_id]);
        $this->boundary('account');

        return $owner->stripe_connect_status === 'active' && filled($owner->stripe_connect_account_id);
    }

    public function cancel(string $subscriptionId): void
    {
        $this->observe('cancel', ['id' => $subscriptionId]);
        if ($this->options['cancel_failure'] ?? false) {
            $this->boundary('cancel_failed');
            throw new BillingUnavailable;
        }
        $row = DB::table('qa_billing_remote')->where('remote_id', $subscriptionId)->sole();
        $state = json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
        DB::table('qa_billing_remote')->where('remote_id', $subscriptionId)->update([
            'state' => json_encode([...$state, 'status' => 'canceled'], JSON_THROW_ON_ERROR),
        ]);
        $this->boundary('cancel');
    }

    public function refund(string $intentId, ?int $amount, ?string $key): array
    {
        if ($key === null || ! DB::table('qa_billing_remote')->where('remote_id', $intentId)->exists()) {
            throw new LogicException('Missing refund key or unknown intent.');
        }
        $raw = $this->create('refund', 're', ['payment_intent' => $intentId, 'amount' => $amount], $key, [
            'object' => 'refund', 'payment_intent' => $intentId, 'status' => $this->options['refund_status'] ?? 'succeeded',
        ]);

        return ['refund_id' => $raw['id'], 'status' => $raw['status']];
    }
}

try {
    $app = PostgresEnvironment::boot();
    [$script, $name, $action, $userId, $targetId, $json] = $argv;
    $options = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    config(['payments.eur_enabled' => ($options['eur_enabled'] ?? false) === true]);
    DB::select("SELECT set_config('application_name', ?, false)", ['equitab-billing-qa-'.$name]);
    Bus::fake();
    Mail::fake();
    Notification::fake();
    $gateway = new BillingConcurrencyGateway($name, $options);
    $app->instance(CheckoutGatewayInterface::class, $gateway);
    $app->instance(BillingReadGatewayInterface::class, $gateway);
    $payments = Mockery::mock(PaymentGatewayInterface::class);
    $payments->shouldReceive('isAccountActive')->andReturnUsing($gateway->isAccountActive(...));
    $payments->shouldReceive('refundPayment')->andReturnUsing($gateway->refund(...));
    $payments->shouldReceive('cancelSubscription')->andReturnUsing($gateway->cancel(...));
    $app->instance(PaymentGatewayInterface::class, $payments);
    DB::table('qa_signals')->insertOrIgnore(['key' => 'started:'.$name]);
    $httpStatus = 200;

    $result = match ($action) {
        'checkout' => app(PaymentService::class)->initiateSubscription(User::findOrFail($userId), Group::findOrFail($targetId), $options['method'] ?? 'pm_pg', $options['invite'] ?? null),
        'join' => (function () use ($userId, $targetId, $options): array {
            app(GroupService::class)->join(User::findOrFail($userId), Group::findOrFail($targetId), $options['invite'] ?? null);

            return ['joined' => true];
        })(),
        'refund' => ['payment_status' => app(PaymentService::class)->refundPayment(Payment::findOrFail($targetId), $options['reason'] ?? 'qa_refund')->status],
        'cancel' => ['confirmed' => app(SubscriptionCancellationService::class)->request(GroupMember::findOrFail($targetId))],
        'synchronize' => ['payment_id' => app(PaymentService::class)->synchronizeSubscription($options['subscription'])?->id],
        'event' => (function () use ($app, $options, &$httpStatus): array {
            config(['services.stripe.webhook_secret' => 'whsec_pg_billing_synthetic', 'services.stripe.connect_webhook_secret' => null]);
            $payload = json_encode($options['event'], JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = hash_hmac('sha256', "$timestamp.$payload", 'whsec_pg_billing_synthetic');
            $request = Request::create('/webhooks/stripe', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t=$timestamp,v1=$signature",
            ], $payload);
            $kernel = $app->make(HttpKernel::class);
            $response = $kernel->handle($request);
            $httpStatus = $response->getStatusCode();
            $kernel->terminate($request, $response);

            return ['processed' => $httpStatus === 200];
        })(),
        default => throw new LogicException('Unsupported billing test action.'),
    };
    // Do not serialize client secrets returned by the checkout facade.
    $result = $httpStatus === 200
        ? ['status' => 200, 'result' => array_intersect_key($result, array_flip(['subscription_id', 'payment_id', 'payment_status', 'confirmed', 'processed', 'joined']))]
        : ['status' => $httpStatus];
} catch (BillingUnavailable $exception) {
    $result = ['status' => 503];
} catch (HttpExceptionInterface $exception) {
    $result = ['status' => $exception->getStatusCode()];
} catch (QueryException $exception) {
    $result = ['status' => 500, 'sqlstate' => $exception->errorInfo[0] ?? null];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}

echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
