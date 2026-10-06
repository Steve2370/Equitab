<?php

declare(strict_types=1);

// No application bootstrap, .env, database or network. Only synthetic config.
$fixtureEnvironment = [];

function env($key, $default = null)
{
    global $fixtureEnvironment;

    return $fixtureEnvironment[$key] ?? $default;
}

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Laravel\Reverb\Application;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\Logger;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Loggers\NullLogger;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Laravel\Reverb\Protocols\Pusher\Server;

$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance('config', new Repository(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]));
$container->instance('cache', new CacheManager($container));
$container->instance('events', new Dispatcher($container));
$container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
$container->instance(Logger::class, new NullLogger);

function configuredApp(array $environment = []): Application
{
    global $fixtureEnvironment;
    $fixtureEnvironment = $environment + ['APP_URL' => 'https://equitab.example.test'];
    $config = require dirname(__DIR__).'/config/reverb.php';
    $app = $config['apps']['apps'][0];

    return new Application('offline', 'synthetic-key', 'synthetic-secret', $app['ping_interval'],
        $app['activity_timeout'], $app['allowed_origins'], $app['max_message_size'],
        $app['max_connections'], $app['accept_client_events_from'], $app['rate_limiting']);
}

function openedConnection(Application $app, string $origin, int $existing = 0): array
{
    $transport = new class implements WebSocketConnection
    {
        public array $messages = [];

        public function id(): int|string
        {
            return 123;
        }

        public function send(mixed $message): void
        {
            $payload = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if (is_string($payload['data'] ?? null)) {
                $payload['data'] = json_decode($payload['data'], true, flags: JSON_THROW_ON_ERROR);
            }
            $this->messages[] = $payload;
        }

        public function close(mixed $message = null): void {}
    };
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('for')->with($app)->andReturnSelf();
    $channels->shouldReceive('connections')->andReturn(array_fill(0, $existing, new stdClass));
    $server = new Server($channels, new EventHandler($channels));
    $connection = new Connection($transport, $app, $origin);
    $server->open($connection);

    return [$server, $connection, $transport];
}

function expectValue(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected result: '.json_encode($actual));
    }
}

$checks = [
    'own origin accepted' => function (): void {
        [, , $transport] = openedConnection(configuredApp(), 'https://equitab.example.test');
        expectValue($transport->messages[0]['event'], 'pusher:connection_established');
    },
    'foreign origin refused' => function (): void {
        [, , $transport] = openedConnection(configuredApp(), 'https://attacker.example.test');
        expectValue($transport->messages[0]['data']['code'], 4009);
    },
    'lookalike origin refused' => function (): void {
        [, , $transport] = openedConnection(configuredApp(), 'https://equitab.example.test.attacker.test');
        expectValue($transport->messages[0]['data']['code'], 4009);
    },
    'explicit additional hostname accepted' => function (): void {
        $app = configuredApp(['REVERB_ALLOWED_ORIGINS' => 'equitab.example.test, www.equitab.example.test']);
        [, , $transport] = openedConnection($app, 'https://www.equitab.example.test');
        expectValue($transport->messages[0]['event'], 'pusher:connection_established');
    },
    'wildcard configuration fails closed' => function (): void {
        $app = configuredApp(['REVERB_ALLOWED_ORIGINS' => '*']);
        [, , $transport] = openedConnection($app, 'https://attacker.example.test');
        expectValue($transport->messages[0]['data']['code'], 4009);
    },
    'development localhost supported' => function (): void {
        [, , $transport] = openedConnection(configuredApp(['APP_URL' => 'http://localhost:8000']), 'http://localhost:8000');
        expectValue($transport->messages[0]['event'], 'pusher:connection_established');
    },
    'default connection quota enforced' => function (): void {
        [, , $transport] = openedConnection(configuredApp(), 'https://equitab.example.test', 500);
        expectValue($transport->messages[0]['data']['code'], 4004);
    },
    'default message quota enforced' => function (): void {
        [$server, $connection, $transport] = openedConnection(configuredApp(), 'https://equitab.example.test');
        for ($i = 0; $i < 60; $i++) {
            $server->message($connection, '{"event":"pusher:ping"}');
            expectValue(end($transport->messages)['event'], 'pusher:pong');
        }
        $server->message($connection, '{"event":"pusher:ping"}');
        expectValue(end($transport->messages)['data']['code'], 4301);
    },
    'client events remain members only' => function (): void {
        expectValue(configuredApp()->acceptClientEventsFrom(), 'members');
    },
];

$failed = [];
foreach ($checks as $name => $check) {
    try {
        $check();
    } catch (Throwable $error) {
        $failed[$name] = $error->getMessage();
    }
}
Mockery::close();
echo json_encode(['passed' => count($checks) - count($failed), 'failed' => count($failed), 'errors' => $failed], JSON_PRETTY_PRINT).PHP_EOL;
exit($failed === [] ? 0 : 1);
