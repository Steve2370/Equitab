<?php

namespace App\Features\Auth\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Features\Auth\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use App\Mail\WelcomeUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            Log::info('Creating user...');
            $user = User::create($request->validated());
            Log::info('User created: ' . $user->id);
            $this->walletService->createForUser($user);
            Log::info('Wallet created for: ' . $user->id);
            return $user;
        });
        Log::info('After transaction: ' . ($user->id ?? 'NULL'));

        try {
            Log::info('Attempting to send welcome email to: ' . $user->email);
            Mail::to($user->email)->send(new WelcomeUser($user));
            Log::info('Welcome email sent successfully to: ' . $user->email);
        } catch (\Exception $e) {
            Log::error('Welcome email failed: ' . $e->getMessage());
        }

        $token = $user->createToken('equitab')->plainTextToken;

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'user' => $user->load('wallet'),
            'token' => $token,
        ], 201);
    }
}
