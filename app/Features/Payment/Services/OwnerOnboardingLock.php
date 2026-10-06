<?php

namespace App\Features\Payment\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

final class OwnerOnboardingLock
{
    /**
     * All owner Stripe readers/writers share the database cache lock, even
     * when the application's ordinary cache uses a process-local store.
     * The callback checks ownership again before committing any response.
     */
    public function run(int $userId, Closure $operation): mixed
    {
        $lock = Cache::store('database')->lock('stripe-owner:'.$userId, 300);
        if (! $lock->get()) {
            throw new OwnerOnboardingException;
        }
        $deadline = microtime(true) + 299;
        $assertOwned = function () use ($lock, $deadline): void {
            if (microtime(true) >= $deadline || ! $lock->isOwnedByCurrentProcess()) {
                throw new OwnerOnboardingException;
            }
        };

        try {
            return $operation($assertOwned);
        } finally {
            $lock->release();
        }
    }
}
