<?php

namespace App\Features\Auth\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => 'Email ou mot de passe incorrect.',
            ]);
        }

        /** @var User $user */
        $user  = Auth::user();
        $token = $user->createToken('equitab')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'user' => $user->load('wallet'),
            'token' => $token,
        ]);
    }
}
