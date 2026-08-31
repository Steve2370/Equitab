<?php

namespace App\Providers;

use App\Features\Group\Repositories\Contracts\GroupRepositoryInterface;
use App\Features\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Features\Wallet\Repositories\Contracts\WalletRepositoryInterface;
use App\Features\Group\Repositories\GroupRepository;
use App\Features\Payment\Repositories\PaymentRepository;
use App\Features\Wallet\Repositories\WalletRepository;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\StripeGateway;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Features\Group\Policies\GroupPolicy;
use App\Models\Group;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GroupRepositoryInterface::class, GroupRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(WalletRepositoryInterface::class, WalletRepository::class);
        $this->app->bind(PaymentGatewayInterface::class, StripeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Group::class, GroupPolicy::class);
        set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
            if (str_contains($errfile, 'stripe-php') && str_contains($errstr, 'Accounts v2')) {
                return true;
            }
            return false;
        }, E_USER_WARNING);
    }
}
