<?php

use App\Features\Auth\Controllers\Api\LoginController;
use App\Features\Auth\Controllers\Api\RegisterController;
use App\Features\Chat\Controllers\ChatController;
use App\Features\Group\Controllers\GroupController;
use App\Features\Group\Controllers\GroupDraftController;
use App\Features\Group\Controllers\ServiceAccessController;
use App\Features\Payment\Controllers\OwnerCountryController;
use App\Features\Payment\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::pattern('group', '[0-9]+');

Route::middleware(['throttle:30,1'])->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login', LoginController::class);
});

Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/groups', [GroupController::class, 'index']);
    Route::get('/groups/{group}', [GroupController::class, 'show']);
    Route::get('/groups/{group}/proration', [PaymentController::class, 'calculateProration']);
});

Route::middleware(['auth:sanctum', 'session_current', 'not_suspended', 'throttle:120,1'])->group(function () {

    Route::middleware('verified')->group(function () {
        Route::patch('/owner/country', OwnerCountryController::class)->middleware('throttle:20,1,owner-country:');
        Route::get('/group-drafts', [GroupDraftController::class, 'index']);
        Route::post('/group-drafts', [GroupDraftController::class, 'store']);
        Route::get('/group-drafts/{draft}', [GroupDraftController::class, 'show'])->whereUuid('draft');
        Route::put('/group-drafts/{draft}', [GroupDraftController::class, 'update'])->whereUuid('draft');
        Route::delete('/group-drafts/{draft}', [GroupDraftController::class, 'destroy'])->whereUuid('draft');
        Route::post('/group-drafts/{draft}/publish', [GroupDraftController::class, 'publish'])->whereUuid('draft')->middleware('throttle:10,1');
        Route::post('/group-drafts/{draft}/reopen', [GroupDraftController::class, 'reopen'])->whereUuid('draft');
    });

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
    Route::middleware('verified')->group(function () {
        Route::get('/groups/{group}/service-access', [ServiceAccessController::class, 'show']);
        Route::put('/groups/{group}/service-access', [ServiceAccessController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/groups/{group}/members/{member}/service-access', [ServiceAccessController::class, 'store'])->whereNumber('member')->middleware('throttle:20,1');
        Route::post('/groups/{group}/members/{member}/service-access/revoke', [ServiceAccessController::class, 'revoke'])->whereNumber('member')->withTrashed()->middleware('throttle:20,1');
    });

    Route::middleware(['throttle:20,1'])->group(function () {
        Route::post('/groups/{group}/subscribe', [PaymentController::class, 'subscribe']);
        Route::post('/groups/{group}/pay', [PaymentController::class, 'initiate']);
        Route::post('/stripe/onboarding', [PaymentController::class, 'startOnboarding']);
        Route::post('/stripe/identity', [PaymentController::class, 'startIdentityVerification']);
    });

    Route::post('/payments/{payment}/dispute', [PaymentController::class, 'dispute']);
    Route::get('/groups/{group}/messages', [ChatController::class, 'show']);
    Route::post('/groups/{group}/messages', [ChatController::class, 'send']);
    Route::get('/groups/{group}/chat-members', [ChatController::class, 'members']);
    Route::post('/subscriptions/confirm', [PaymentController::class, 'confirmSubscription']);
});
