<?php

use App\Features\Auth\Controllers\Api\LoginController;
use App\Features\Auth\Controllers\Api\RegisterController;
use App\Features\Group\Controllers\GroupController;
use App\Features\Payment\Controllers\PaymentController;
use App\Features\Wallet\Controllers\WalletController;
use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:30,1'])->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login', LoginController::class);
});

Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/groups', [GroupController::class, 'index']);
    Route::get('/groups/{group}', [GroupController::class, 'show']);
    Route::get('/groups/{group}/proration', [PaymentController::class, 'calculateProration']);
});

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {

    Route::post('/logout', function () {
        request()->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté.']);
    });

    Route::post('/groups', [GroupController::class, 'store']);
    Route::put('/groups/{group}', [GroupController::class, 'update']);
    Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
    Route::post('/groups/{group}/join', [GroupController::class, 'join']);
    Route::post('/groups/{group}/leave', [GroupController::class, 'leave']);
    Route::get('/groups/{group}/credentials', [GroupController::class, 'credentials']);

    Route::middleware(['throttle:20,1'])->group(function () {
        Route::post('/groups/{group}/subscribe', [PaymentController::class, 'subscribe']);
        Route::post('/groups/{group}/pay', [PaymentController::class, 'initiate']);
        Route::post('/stripe/onboarding', [PaymentController::class, 'startOnboarding']);
        Route::post('/stripe/identity', [PaymentController::class, 'startIdentityVerification']);
    });

    Route::post('/payments/{payment}/dispute', [PaymentController::class, 'dispute']);
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/credit', [WalletController::class, 'credit']);
    Route::get('/groups/{group}/messages', [ChatController::class, 'show']);
    Route::post('/groups/{group}/messages', [ChatController::class, 'send']);
    Route::post('/subscriptions/confirm', [PaymentController::class, 'confirmSubscription']);
});
