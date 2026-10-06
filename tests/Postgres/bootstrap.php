<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

// Refuse to use an ordinary database or the project's .env. The test root must
// be created explicitly with mktemp and host a private, disposable PG cluster.
$testRoot = getenv('EQUITAB_PG_TEST_ROOT');
if (! is_string($testRoot) || ! preg_match('~^/(private/)?tmp/equitab-owner-pg\.[A-Za-z0-9]+$~', $testRoot)
    || realpath($testRoot) !== $testRoot || ! is_dir($testRoot.'/socket') || ! is_dir($testRoot.'/env')
    || is_file($testRoot.'/env/.env') || is_file($testRoot.'/env/.env.testing')
    || is_file($testRoot.'/absent-config.php')) {
    throw new RuntimeException('Set EQUITAB_PG_TEST_ROOT to an isolated, explicitly initialized test cluster.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('p', 32)),
    'APP_URL' => 'http://localhost',
    'APP_CONFIG_CACHE' => $testRoot.'/absent-config.php',
    'DB_CONNECTION' => 'pgsql',
    'DB_URL' => '',
    'DB_HOST' => $testRoot.'/socket',
    'DB_PORT' => '55467',
    'DB_DATABASE' => 'equitab_owner_qa',
    'DB_USERNAME' => 'eq_owner_qa',
    'DB_PASSWORD' => '',
    'DB_CACHE_CONNECTION' => 'pgsql',
    'DB_CACHE_LOCK_CONNECTION' => 'pgsql',
    'DB_CACHE_TABLE' => 'cache',
    'DB_CACHE_LOCK_TABLE' => 'cache_locks',
    'CACHE_PREFIX' => 'equitab-pg-qa-',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'BCRYPT_ROUNDS' => '4',
    'STRIPE_SECRET' => 'sk_test_offline_postgres',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require_once __DIR__.'/PostgresEnvironment.php';
