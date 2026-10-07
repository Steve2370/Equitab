<?php

require dirname(__DIR__).'/Postgres/bootstrap.php';

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Services\BillingUnavailable;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Postgres\PostgresEnvironment;

try {
    PostgresEnvironment::boot();
    DB::statement("SET application_name = 'visibility-qa-worker'");
    $group = app(GroupService::class)->update(User::findOrFail($argv[1]), Group::findOrFail($argv[2]), ['visibility' => 'private']);
    echo json_encode(['visibility' => $group->visibility, 'token' => $group->invite_token], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (BillingUnavailable) {
    echo json_encode(['status' => 503], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}
