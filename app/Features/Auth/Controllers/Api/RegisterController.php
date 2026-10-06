<?php

namespace App\Features\Auth\Controllers\Api;

use App\Features\Auth\Requests\RegisterRequest;
use App\Http\Controllers\Controller;
use App\Mail\WelcomeUser;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        [$user, $token] = DB::transaction(function () use ($request): array {
            $user = User::create($request->validated());

            return [$user, $user->createToken('equitab')->plainTextToken];
        });

        try {
            Mail::to($user->email)->send(new WelcomeUser($user));
        } catch (\Exception) {
            Log::warning('Welcome email failed.', ['user_id' => $user->id]);
        }

        event(new Registered($user));

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}
