<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillingCustomerService
{
    public function __construct(private readonly CheckoutGatewayInterface $gateway, private readonly BillingOperationLock $lock) {}

    public function customer(User $payer): string
    {
        return $this->lock->run('customer:'.$payer->id, function (Closure $assertOwned) use ($payer): string {
            $user = $payer->fresh();
            abort_unless($user && ! $user->isSuspended() && $user->status !== 'banned', 403);
            if ($user->stripe_customer_id) {
                return $user->stripe_customer_id;
            }
            $attempt = DB::table('billing_customer_attempts')->where('user_id', $user->id)->first();
            if (! $attempt) {
                DB::table('billing_customer_attempts')->insert([
                    'user_id' => $user->id, 'idempotency_key' => (string) Str::uuid(),
                    'parameters' => Crypt::encryptString(json_encode(['email' => $user->email, 'name' => $user->name], JSON_THROW_ON_ERROR)),
                    'started_at' => now(),
                ]);
                $attempt = DB::table('billing_customer_attempts')->where('user_id', $user->id)->first();
            }
            if (Carbon::parse($attempt->started_at)->lte(now()->subHours(23))) {
                throw new BillingUnavailable;
            }
            $id = $this->gateway->createCustomer(json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR), $attempt->idempotency_key);
            if (! str_starts_with($id, 'cus_')) {
                throw new BillingUnavailable;
            }
            DB::transaction(function () use ($user, $id, $assertOwned): void {
                $current = User::withTrashed()->lockForUpdate()->findOrFail($user->id);
                $assertOwned();
                if ($current->stripe_customer_id && $current->stripe_customer_id !== $id) {
                    throw new BillingUnavailable;
                }
                $current->update(['stripe_customer_id' => $id]);
            });

            return $id;
        });
    }
}
