<?php

namespace App\Features\Auth\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        [$user, $token] = DB::transaction(function () use ($credentials): array {
            // Serializes token issuance with password recovery/revocation.
            $user = User::query()->where('email', $credentials['email'])->lockForUpdate()->first();

            if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => 'Email ou mot de passe incorrect.']);
            }

            abort_unless($user->canAccessAccount(), 403, 'Ce compte est suspendu ou désactivé.');

            return [$user, $user->createToken('equitab')->plainTextToken];
        });

        return response()->json([
            'message' => 'Connexion réussie.',
            'user' => $user,
            'token' => $token,
        ]);
    }
}
