<?php

// use App\Http\Controllers\ProfileController;
use App\Features\Admin\Controllers\AdminController;
use App\Features\Chat\Controllers\ChatController;
use App\Features\Dashboard\Controllers\DashboardController;
use App\Features\Group\Controllers\GroupController;
use App\Features\Group\Controllers\GroupDraftController;
use App\Features\Payment\Controllers\PaymentController;
use App\Features\Payment\Controllers\StripeWebhookController;
use App\Features\Subscription\Controllers\SubscriptionController;
use App\Models\Group;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::pattern('group', '[0-9]+');

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('admin.index');
    Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
    Route::get('/groups', [AdminController::class, 'groups'])->name('admin.groups');
    Route::get('/payments', [AdminController::class, 'payments'])->name('admin.payments');
    Route::get('/disputes', [AdminController::class, 'disputes'])->name('admin.disputes');
    Route::patch('/disputes/{dispute}/resolve', [AdminController::class, 'resolveDispute'])->name('admin.disputes.resolve');
    Route::get('/messages', [AdminController::class, 'messages'])->name('admin.messages');
    Route::post('/messages/send', [AdminController::class, 'sendMessage'])->name('admin.messages.send');
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('admin.users.delete');
    Route::post('/users/{user}/suspend', [AdminController::class, 'suspendUser'])->name('admin.users.suspend');
    Route::post('/users/{user}/unsuspend', [AdminController::class, 'unsuspendUser'])->name('admin.users.unsuspend');
});

Route::post('/webhooks/stripe', StripeWebhookController::class);

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    // Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard/chat', [ChatController::class, 'index']);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard/preferences', [DashboardController::class, 'preferences']);
    Route::patch('/dashboard/preferences', [DashboardController::class, 'updatePreferences']);
    Route::post('/dashboard/preferences/avatar', [DashboardController::class, 'updateAvatar']);
    Route::delete('/dashboard/preferences/account', [DashboardController::class, 'deleteAccount']);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/subscriptions', [DashboardController::class, 'subscriptions'])
        ->name('dashboard.subscriptions');
    Route::get('/dashboard/payments', [DashboardController::class, 'payments']);
    Route::get('/dashboard/profile', [DashboardController::class, 'profile']);
    Route::patch('/dashboard/profile', [DashboardController::class, 'updateProfile']);
});

Route::get('/conditions', fn () => Inertia::render('Legal/Terms'))->name('legal.terms');
Route::get('/confidentialite', fn () => Inertia::render('Legal/Privacy'))->name('legal.privacy');
Route::get('/charte', fn () => Inertia::render('Legal/Trust'))->name('legal.trust');

Route::get('/groups/service/{slug}', [GroupController::class, 'byService'])
    ->name('groups.by-service');

Route::get('/services', [SubscriptionController::class, 'index'])->name('services.index');

require __DIR__.'/auth.php';

Route::get('/', function () {
    $catalogServices = Subscription::where('is_active', true)
        ->get()
        ->map(fn ($sub) => [
            'name' => $sub->name,
            'slug' => $sub->slug,
            'pricePerMember' => $sub->monthly_price / 100,
            'discountPercent' => 50,
        ]);

    $openGroups = Group::with(['subscription', 'owner'])
        ->where('status', 'open')
        ->where('visibility', 'public')
        ->whereHas('owner')
        ->limit(10)
        ->get()
        ->map(fn ($group) => [
            'id' => $group->id,
            'subscriptionName' => $group->subscription->name,
            'subscriptionSlug' => $group->subscription->slug,
            'ownerName' => $group->owner->display_name,
            'pricePerMember' => $group->calculateCurrentPricePerMember(),
            'currentMembers' => $group->current_members,
            'maxMembers' => $group->max_members,
        ]);

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'isAuthenticated' => Auth::check(),
        'catalogServices' => $catalogServices,
        'openGroups' => $openGroups,
    ]);
});

Route::get('/payment/success', [PaymentController::class, 'success'])
    ->middleware(['auth', 'verified'])
    ->name('payment.success');

Route::get('/dashboard/groups/create', [GroupController::class, 'create'])
    ->middleware(['auth', 'verified'])->name('groups.create');

Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function () {
    Route::get('/dashboard/groups/drafts/{draft}', [GroupDraftController::class, 'edit'])->whereUuid('draft')->name('group-drafts.edit');
    Route::get('/group-drafts', [GroupDraftController::class, 'index']);
    Route::post('/group-drafts', [GroupDraftController::class, 'store']);
    Route::get('/group-drafts/{draft}', [GroupDraftController::class, 'show'])->whereUuid('draft');
    Route::put('/group-drafts/{draft}', [GroupDraftController::class, 'update'])->whereUuid('draft');
    Route::delete('/group-drafts/{draft}', [GroupDraftController::class, 'destroy'])->whereUuid('draft');
    Route::post('/group-drafts/{draft}/publish', [GroupDraftController::class, 'publish'])->whereUuid('draft')->middleware('throttle:10,1');
    Route::post('/group-drafts/{draft}/reopen', [GroupDraftController::class, 'reopen'])->whereUuid('draft');
    Route::get('/stripe/onboarding/return', [PaymentController::class, 'returnFromOnboarding'])->name('stripe.onboarding.return');
    Route::get('/stripe/onboarding/refresh', [PaymentController::class, 'refreshOnboarding'])->name('stripe.onboarding.refresh');
});

Route::post('/groups', [GroupController::class, 'store'])
    ->middleware(['auth', 'verified'])
    ->name('groups.store');

Route::patch('/groups/{group}/close', [GroupController::class, 'close'])
    ->middleware(['auth', 'verified']);

Route::get('/invite/{token}', [GroupController::class, 'showInvite'])->name('invite.show');
