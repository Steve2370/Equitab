<?php

namespace App\Repositories\Contracts;

use App\Models\Wallet;

interface WalletRepositoryInterface
{
    public function findByUserId(int $userId): ?Wallet;
    public function credit(Wallet $wallet, int $amountInCents): Wallet;
    public function debit(Wallet $wallet, int $amountInCents): Wallet;
    public function hasSufficientBalance(Wallet $wallet, int $amountInCents): bool;
}
