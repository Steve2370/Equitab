<?php

namespace Tests\Postgres;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class PostgresEnvironment
{
    public static function boot(): Application
    {
        $root = getenv('EQUITAB_PG_TEST_ROOT');
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($root.'/env');
        $app->make(Kernel::class)->bootstrap();
        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $root.'/laravel.log',
        ]);
        Http::preventStrayRequests();
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                throw new \LogicException('Stripe network is forbidden in the PostgreSQL suite.');
            }
        });
        self::guard();
        DB::statement("SET statement_timeout = '15s'");
        DB::statement("SET lock_timeout = '10s'");

        return $app;
    }

    public static function guard(): void
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== 'equitab_owner_qa') {
            throw new \RuntimeException('Refusing a non-test database.');
        }
        $state = $connection->selectOne("SELECT current_database() AS db, current_user AS role,
            current_setting('data_directory') AS directory, inet_server_addr() AS address");
        if ($state->db !== 'equitab_owner_qa' || $state->role !== 'eq_owner_qa'
            || $state->directory !== getenv('EQUITAB_PG_TEST_ROOT').'/cluster' || $state->address !== null) {
            throw new \RuntimeException('The PostgreSQL connection is not the private disposable cluster.');
        }
    }
}
