<?php

namespace App\Features\Group\Services;

use App\Support\Currency;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Resolve an old group's native currency without changing any financial record. */
final class LegacyGroupCurrency
{
    public function resolve(int $groupId, int $subscriptionId): string
    {
        try {
            $currencies = DB::table('payments')->where('group_id', $groupId)->pluck('currency')
                ->concat(DB::table('stripe_prices')->where('group_id', $groupId)->pluck('currency'));

            foreach (DB::table('subscription_attempts')->where('group_id', $groupId)->cursor() as $attempt) {
                $parameters = json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR);
                $currency = $parameters['price']['currency'] ?? null;
                if (! is_string($currency)) {
                    throw new RuntimeException('Missing currency in a persisted payment attempt.');
                }
                $currencies->push($currency);
            }

            if ($currencies->isEmpty()) {
                $currencies->push(DB::table('subscriptions')->where('id', $subscriptionId)->value('currency'));
            }
            $currencies = $currencies->map(fn ($value) => Currency::normalize($value))->unique()->values();
            if ($currencies->count() !== 1) {
                throw new RuntimeException('Conflicting financial currencies.');
            }

            return $currencies->sole();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Devise du groupe #{$groupId} à rapprocher avant migration ; aucune conversion automatique n'est autorisée.",
                previous: $exception,
            );
        }
    }
}
