<?php

namespace App\Providers;

use App\Features\Auth\Notifications\VerificationMail;
use App\Features\Group\Contracts\GroupProductGateway;
use App\Features\Group\Policies\GroupPolicy;
use App\Features\Group\Repositories\Contracts\GroupRepositoryInterface;
use App\Features\Group\Repositories\GroupRepository;
use App\Features\Group\Services\StripeGroupProductGateway;
use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\StripeBillingGateway;
use App\Features\Payment\Services\StripeGateway;
use App\Features\Payment\Services\StripeOwnerGateway;
use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn () => new StripeClient(config('services.stripe.secret')));
        $this->app->bind(GroupProductGateway::class, StripeGroupProductGateway::class);
        $this->app->bind(OwnerStripeGatewayInterface::class, StripeOwnerGateway::class);
        $this->app->bind(GroupRepositoryInterface::class, GroupRepository::class);
        $this->app->bind(BillingReadGatewayInterface::class, StripeBillingGateway::class);
        $this->app->bind(CheckoutGatewayInterface::class, StripeBillingGateway::class);
        $this->app->bind(PaymentGatewayInterface::class, StripeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Group::class, GroupPolicy::class);
        VerifyEmail::toMailUsing(fn (User $user, string $url): MailMessage => app(VerificationMail::class)($user, $url));
        set_error_handler(function (int $errno, string $errstr, string $errfile): bool {
            if (str_contains($errfile, 'stripe-php') && str_contains($errstr, 'Accounts v2')) {
                return true;
            }

            return false;
        }, E_USER_WARNING);
    }
}
