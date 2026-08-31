<?php

namespace App\Features\Wallet\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Features\Wallet\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Exception;

class WalletService
{
    public function __construct(
        private readonly WalletRepositoryInterface $walletRepository,
    ) {}

    public function createForUser(User $user): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => 'CAD',
        ]);
    }

    public function credit(User $user, int $amountInCents, string $description = ''): Transaction
    {
        return DB::transaction(function () use ($user, $amountInCents, $description) {
            $wallet = $this->walletRepository->findByUserId($user->id);

            $wallet = $this->walletRepository->credit($wallet, $amountInCents);

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $amountInCents,
                'balance_after' => $wallet->balance,
                'description' => $description,
                'status' => 'completed',
            ]);
        });
    }

    public function debit(User $user, int $amountInCents, string $type, string $description = ''): Transaction
    {
        return DB::transaction(function () use ($user, $amountInCents, $type, $description) {
            $wallet = $this->walletRepository->findByUserId($user->id);

            if (! $this->walletRepository->hasSufficientBalance($wallet, $amountInCents)) {
                throw new Exception('Solde insuffisant.');
            }

            $wallet = $this->walletRepository->debit($wallet, $amountInCents);

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amountInCents,
                'balance_after' => $wallet->balance,
                'description' => $description,
                'status' => 'completed',
            ]);
        });
    }
}
