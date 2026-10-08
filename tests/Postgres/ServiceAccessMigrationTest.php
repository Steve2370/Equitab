<?php

namespace Tests\Postgres;

use App\Features\Group\Services\ServiceAccessDelivery;
use App\Features\Payment\Services\SubscriptionCancellationService;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

class ServiceAccessMigrationTest extends PostgresTestCase
{
    public function test_delivery_serializes_with_cancellation_before_acquiring_any_sql_row_lock(): void
    {
        $subscription = Subscription::where('slug', 'dropbox-family')->sole();
        $group = Group::factory()->for($subscription)->create();
        $member = GroupMember::factory()->for($group)->create();
        $code = <<<'PHP'
require 'tests/Postgres/bootstrap.php';
Tests\Postgres\PostgresEnvironment::boot();
$group = App\Models\Group::findOrFail((int)$argv[1]);
$member = App\Models\GroupMember::findOrFail((int)$argv[2]);
try {
    app(App\Features\Group\Services\ServiceAccessDelivery::class)->deliver($group->owner, $group, $member, ['invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-concurrent']);
    echo 'delivered';
} catch (App\Features\Payment\Services\BillingUnavailable) {
    echo 'retry';
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
    echo 'refused:'.$error->getStatusCode();
}
PHP;
        $args = [PHP_BINARY, '-r', $code, (string) $group->id, (string) $member->id];
        $lock = Cache::store('database')->lock('billing:group:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            $worker = new Process($args, base_path(), timeout: 20);
            $worker->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertSame('retry', $worker->getOutput());
            $this->assertNull($member->fresh()->service_invitation_provided_at);
        } finally {
            $lock->release();
        }
        $this->assertTrue(app(SubscriptionCancellationService::class)->request($member));
        $worker = new Process($args, base_path(), timeout: 20);
        $worker->run();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $this->assertSame('refused:409', $worker->getOutput());
        $this->assertNull($member->fresh()->service_invitation_provided_at);
        $this->assertSame('left', $member->fresh()->status);
    }

    public function test_migration_preserves_legacy_group_money_secrets_and_payment_contract(): void
    {
        $group = Group::factory()->withCredentials()->create();
        $member = GroupMember::factory()->for($group)->create();
        $payment = Payment::factory()->for($group)->for($member->user)->create();
        $before = [$group->currency, $group->total_price, $group->getRawOriginal('credential_password'), $payment->amount];
        $migration = require database_path('migrations/2026_10_08_000200_add_service_access_delivery.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('groups', 'access_mode'));
        $migration->up();
        $group->refresh();
        $payment->refresh();
        $this->assertSame($before, [$group->currency, $group->total_price, $group->getRawOriginal('credential_password'), $payment->amount]);
        $this->assertSame('credentials', $group->access_mode);
        $this->assertSame(1, $payment->access_check_version);
        $this->assertNull($member->fresh()->service_invitation_provided_at);
    }

    public function test_rollback_refuses_to_destroy_new_financial_contracts(): void
    {
        Payment::factory()->create(['access_check_version' => 2]);
        $migration = require database_path('migrations/2026_10_08_000200_add_service_access_delivery.php');
        try {
            $migration->down();
            $this->fail('A populated access contract must not be destroyed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Conservez les colonnes', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('payments', 'access_check_version'));
        $this->assertDatabaseHas('payments', ['access_check_version' => 2]);
    }

    public function test_cross_process_delivery_lock_prevents_refund_decision_and_allows_safe_retry(): void
    {
        $subscription = Subscription::where('slug', 'dropbox-family')->sole();
        $group = Group::factory()->for($subscription)->create();
        $member = GroupMember::factory()->for($group)->create();
        $payment = Payment::factory()->for($group)->for($member->user)->create(['access_check_version' => 2]);
        $lock = Cache::store('database')->lock('billing:access-delivery:'.$group->id, 300);
        $this->assertTrue($lock->get());
        $code = <<<'PHP'
require 'tests/Postgres/bootstrap.php';
Tests\Postgres\PostgresEnvironment::boot();
try {
    (new App\Jobs\CheckCredentialsProvided((int)$argv[1], (int)$argv[2], (int)$argv[3]))->handle(app(App\Features\Payment\Services\BillingReconciliationService::class));
    echo 'checked';
} catch (App\Features\Payment\Services\BillingUnavailable) {
    echo 'retry';
}
PHP;
        $args = [PHP_BINARY, '-r', $code, (string) $payment->id, (string) $group->id, (string) $member->user_id];
        try {
            $worker = new Process($args, base_path(), timeout: 20);
            $worker->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertSame('retry', $worker->getOutput());
            $this->assertDatabaseCount('payment_refund_attempts', 0);
        } finally {
            $lock->release();
        }
        app(ServiceAccessDelivery::class)->deliver($group->owner, $group, $member, ['invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-pg']);
        $worker = new Process($args, base_path(), timeout: 20);
        $worker->run();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $this->assertSame('checked', $worker->getOutput());
        $this->assertDatabaseCount('payment_refund_attempts', 0);
        $this->assertStringNotContainsString('synthetic-pg', DB::table('group_members')->where('id', $member->id)->value('service_invitation_url'));
    }
}
