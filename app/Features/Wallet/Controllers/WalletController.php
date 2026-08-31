<?php

namespace App\Features\Wallet\Controllers;

use App\Http\Controllers\Controller;
use App\Features\Wallet\Repositories\Contracts\WalletRepositoryInterface;
use App\Features\Wallet\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly WalletService $walletService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->walletRepository->findByUserId($request->user()->id);

        return response()->json([
            'balance' => $wallet->balance,
            'balance_dollars' => $wallet->balance_in_dollars,
            'currency' => $wallet->currency,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->walletRepository->findByUserId($request->user()->id);

        $transactions = $wallet->transactions()
            ->latest()
            ->paginate(20);

        return response()->json($transactions);
    }

    public function credit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'integer', 'min:100'],
            'description' => ['sometimes', 'string'],
        ]);

        $transaction = $this->walletService->credit(
            $request->user(),
            $request->amount,
            $request->description ?? 'Rechargement'
        );

        return response()->json([
            'message' => 'Wallet rechargé.',
            'transaction' => $transaction,
        ]);
    }
}
