<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

abstract class BillingTestCase extends TestCase
{
    private array $originalEnvironment = [];

    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'APP_CONFIG_CACHE' => '/private/tmp/equitab-billing-no-config.php',
            'APP_ROUTES_CACHE' => '/private/tmp/equitab-billing-no-routes.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
            // Quote the channel name: Laravel parses bare "null" as PHP null.
            'STRIPE_SECRET' => 'sk_test_billing_no_network', 'LOG_CHANNEL' => '"null"',
        ] as $name => $value) {
            $this->originalEnvironment[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/no-environment-files');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertEmpty(config('database.connections.sqlite.url'));
        Http::preventStrayRequests();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        $this->withoutVite();
        $original = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new \RuntimeException('Stripe network forbidden in billing tests.'));
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        try {
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        } finally {
            foreach ($this->originalEnvironment as $name => [$process, $env, $server]) {
                putenv($process === false ? $name : $name.'='.$process);
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }
}
