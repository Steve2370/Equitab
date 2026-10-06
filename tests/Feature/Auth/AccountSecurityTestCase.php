<?php

namespace Tests\Feature\Auth;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

abstract class AccountSecurityTestCase extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'APP_CONFIG_CACHE' => '/private/tmp/equitab-auth-no-config-cache.php',
            'APP_ROUTES_CACHE' => '/private/tmp/equitab-auth-no-routes-cache.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
            'STRIPE_SECRET' => 'sk_test_auth_no_network',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_auth_synthetic',
            'STRIPE_CONNECT_WEBHOOK_SECRET' => '',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/no-environment-files');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->withoutVite();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();

        $original = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new \RuntimeException('Outbound Stripe request blocked by account tests'));
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
    }
}
