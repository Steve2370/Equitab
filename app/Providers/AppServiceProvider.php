<?php

namespace App\Providers;

use App\Repositories\Contracts\GroupRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\GroupRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletRepository;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\StripeGateway;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
            if (str_contains($errfile, 'stripe-php') && str_contains($errstr, 'Accounts v2')) {
                return true;
            }
            return false;
        }, E_USER_WARNING);
    }
}
