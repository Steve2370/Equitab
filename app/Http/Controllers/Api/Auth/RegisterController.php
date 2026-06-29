<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use App\Mail\WelcomeUser;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->validated());
            $this->walletService->createForUser($user);
            return $user;
        });

        Mail::to($user->email)->send(new WelcomeUser($user));

        $token = $user->createToken('equitab')->plainTextToken;

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'user' => $user->load('wallet'),
            'token' => $token,
        ], 201);
    }
}
