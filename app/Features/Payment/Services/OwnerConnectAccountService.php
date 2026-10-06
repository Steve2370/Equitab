<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OwnerConnectAccountService
{
    public function __construct(
        private readonly OwnerStripeGatewayInterface $gateway,
        private readonly OwnerOnboardingAccess $access,
        private readonly OwnerOnboardingLock $lock,
    ) {}

    public function accountId(User $user, ?string $draftId = null): string
    {
        $current = $user->fresh();
        abort_unless($current, 403);
        $this->access->authorize($current, $draftId);
        if ($current->stripe_connect_account_id) {
            return $current->stripe_connect_account_id;
        }

        // The attempt must commit BEFORE the network call, including on a lost
        // response or process crash. A caller's outer transaction would undo it.
        if (DB::transactionLevel() !== 0) {
            throw new OwnerOnboardingException;
        }

        return $this->lock->run($user->id, function (Closure $assertOwned) use ($user, $draftId): string {
            DB::transaction(function () use ($user, $draftId): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $this->access->authorize($locked, $draftId);
                if ($locked->stripe_connect_account_id || DB::table('owner_connect_attempts')->where('user_id', $locked->id)->exists()) {
                    return;
                }

                DB::table('owner_connect_attempts')->insert([
                    'user_id' => $locked->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'parameters' => Crypt::encryptString(json_encode($this->parameters($locked), JSON_THROW_ON_ERROR)),
                    'started_at' => now(),
                ]);
            });

            $current = $user->fresh();
            $this->access->authorize($current, $draftId);
            if ($current->stripe_connect_account_id) {
                return $current->stripe_connect_account_id;
            }

            $attempt = DB::table('owner_connect_attempts')->where('user_id', $user->id)->first();
            // Stripe may discard keys after 24h. Older ambiguous attempts need
            // operator reconciliation; recreating could duplicate the account.
            if (! $attempt || Carbon::parse($attempt->started_at)->lte(now()->subHours(23))) {
                throw new OwnerOnboardingException;
            }

            $parameters = json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR);
            $id = $this->gateway->createAccount($parameters, $attempt->idempotency_key);
            if ($id === '') {
                throw new OwnerOnboardingException;
            }

            DB::transaction(function () use ($user, $id, $draftId, $assertOwned): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $assertOwned();
                $this->access->authorize($locked, $draftId);
                if ($locked->stripe_connect_account_id && $locked->stripe_connect_account_id !== $id) {
                    throw new OwnerOnboardingException;
                }
                $locked->update(['stripe_connect_account_id' => $id]);
            });

            return $id;
        });
    }

    /** @return array<string, mixed> */
    private function parameters(User $user): array
    {
        $parameters = [
            'type' => 'express',
            'country' => 'CA',
            'email' => $user->email,
            'business_type' => 'individual',
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ];
        $province = strtoupper(trim((string) $user->province));
        $postalCode = strtoupper(trim((string) $user->postal_code));
        // Only complete, user-supplied Canadian addresses are prefilled.
        // Stripe's hosted form lets the owner confirm/correct these fields.
        if (filled($user->address) && filled($user->city)
            && in_array($province, ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'], true)
            && preg_match('/^[ABCEGHJ-NPRSTVXY][0-9][ABCEGHJ-NPRSTV-Z] ?[0-9][ABCEGHJ-NPRSTV-Z][0-9]$/', $postalCode)) {
            $parameters['individual']['address'] = [
                'line1' => trim($user->address),
                'city' => trim($user->city),
                'state' => $province,
                'postal_code' => $postalCode,
                'country' => 'CA',
            ];
        }

        return $parameters;
    }
}
