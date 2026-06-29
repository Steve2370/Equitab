<?php

namespace App\Repositories;

use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;

class WalletRepository implements WalletRepositoryInterface
{
    public function findByUserId(int $userId): ?Wallet
    {
        return Wallet::where('user_id', $userId)->first();
    }

    public function credit(Wallet $wallet, int $amountInCents): Wallet
    {
        $wallet->increment('balance', $amountInCents);
        return $wallet->fresh();
    }

    public function debit(Wallet $wallet, int $amountInCents): Wallet
    {
        $wallet->decrement('balance', $amountInCents);
        return $wallet->fresh();
    }

    public function hasSufficientBalance(Wallet $wallet, int $amountInCents): bool
    {
        return $wallet->balance >= $amountInCents;
    }
}
