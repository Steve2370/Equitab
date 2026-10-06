<?php

namespace App\Features\Payment\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

final class BillingOperationLock
{
    public function run(string $scope, Closure $operation): mixed
    {
        $lock = Cache::store('database')->lock('billing:'.$scope, 300);
        if (! $lock->get()) {
            throw new BillingUnavailable;
        }
        $deadline = microtime(true) + 299;
        $assertOwned = static function () use ($lock, $deadline): void {
            if (microtime(true) >= $deadline || ! $lock->isOwnedByCurrentProcess()) {
                throw new BillingUnavailable;
            }
        };
        try {
            return $operation($assertOwned);
        } finally {
            $lock->release();
        }
    }
}
