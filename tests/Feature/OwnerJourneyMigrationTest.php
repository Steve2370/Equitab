<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\StripePrice;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupDraftTestCase;

class OwnerJourneyMigrationTest extends GroupDraftTestCase
{
    private const MIGRATIONS = [
        'group_drafts' => '2026_10_06_120000_create_group_drafts_table.php',
        'owner_connect_attempts' => '2026_10_06_120100_create_owner_connect_attempts_table.php',
    ];

    public static function newMigrationScopes(): array
    {
        return [
            'drafts only' => [['group_drafts']],
            'Connect attempts only' => [['owner_connect_attempts']],
            'both new migrations' => [['group_drafts', 'owner_connect_attempts']],
        ];
    }

    #[DataProvider('newMigrationScopes')]
    public function test_new_migrations_upgrade_rollback_and_reapply_without_changing_legacy_data(array $tables): void
    {
        // The inherited guard checks SQLite :memory: before RefreshDatabase can migrate.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $migrations = [];
        foreach ($tables as $table) {
            $migrations[$table] = require database_path('migrations/'.self::MIGRATIONS[$table]);
            $this->assertInstanceOf(Migration::class, $migrations[$table]);
            $this->assertDatabaseCount($table, 0);
        }

        // Reconstruct the schema before this upgrade using only the two new down() methods.
        foreach (array_reverse($migrations, true) as $table => $migration) {
            $migration->down();
            $this->assertFalse(Schema::hasTable($table));
        }
        $group = $this->seedLegacyGroupWithMembersAndPayments();
        $before = $this->legacySnapshot();

        foreach ($migrations as $table => $migration) {
            $migration->up();
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame($before, $this->legacySnapshot(), "Upgrade of {$table} changed existing data or schema.");
            $this->populateNewTable($table, $group);
        }

        // Roll back populated tables, including a published draft referencing the old group.
        foreach (array_reverse($migrations, true) as $table => $migration) {
            $migration->down();
            $this->assertFalse(Schema::hasTable($table));
            $this->assertSame($before, $this->legacySnapshot(), "Rollback of {$table} changed existing data or schema.");
        }

        foreach ($migrations as $table => $migration) {
            $migration->up();
            $this->assertDatabaseCount($table, 0);
            $this->assertSame($before, $this->legacySnapshot(), "Reapplying {$table} changed existing data or schema.");
        }
        $this->assertSame('legacy-encrypted-password-949', $group->fresh()->credential_password);
        $this->assertSame(2, $group->fresh()->members()->where('status', 'active')->count());
        $this->assertSame(2, $group->fresh()->payments()->count());
    }

    private function seedLegacyGroupWithMembersAndPayments(): Group
    {
        $owner = $this->readyOwner();
        $payer = User::factory()->create();
        $group = Group::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Groupe préexistant aux brouillons',
            'current_members' => 2,
            'total_price' => 2400,
            'credential_password' => 'legacy-encrypted-password-949',
        ]);
        GroupMember::factory()->owner()->create([
            'group_id' => $group->id, 'user_id' => $owner->id, 'share_amount' => 1200,
        ]);
        GroupMember::factory()->withStripeSubscription('sub_legacy_migration')->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'share_amount' => 1200,
            'stripe_subscription_item_id' => 'si_legacy_migration',
        ]);
        Payment::factory()->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'amount' => 1200,
            'platform_fee_amount' => 100, 'stripe_payment_intent_id' => 'pi_legacy_completed',
        ]);
        Payment::factory()->refunded()->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'amount' => 1200,
            'platform_fee_amount' => 100, 'stripe_payment_intent_id' => 'pi_legacy_refunded',
            'stripe_refund_id' => 're_legacy_migration',
        ]);
        StripePrice::create([
            'group_id' => $group->id, 'stripe_product_id' => 'prod_legacy_migration',
            'stripe_price_id' => 'price_legacy_migration', 'unit_amount' => 2400, 'currency' => 'CAD',
        ]);

        return $group;
    }

    private function legacySnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'subscriptions', 'groups', 'group_members', 'payments', 'stripe_prices'] as $table) {
            $snapshot[$table] = [
                'columns' => Schema::getColumns($table),
                'indexes' => Schema::getIndexes($table),
                'foreign_keys' => Schema::getForeignKeys($table),
                'rows' => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ];
        }

        return $snapshot;
    }

    private function populateNewTable(string $table, Group $group): void
    {
        if ($table === 'group_drafts') {
            $draft = new GroupDraft;
            $draft->forceFill(['owner_id' => $group->owner_id, 'data' => ['name' => 'Après migration']])->save();
            $draft->refresh();
            $this->assertTrue(Str::isUuid($draft->id));
            $this->assertSame(1, $draft->version);
            $this->assertSame('draft', $draft->status);
            $this->assertSame(['name' => 'Après migration'], $draft->data);
            $this->assertNull($draft->published_group_id);
            $this->assertNotNull($draft->created_at);
            $this->assertNotNull($draft->updated_at);
            $draft->forceFill(['status' => 'published', 'published_group_id' => $group->id])->save();
            $this->assertDatabaseHas('group_drafts', ['id' => $draft->id, 'published_group_id' => $group->id]);

            return;
        }

        DB::table('owner_connect_attempts')->insert([
            'user_id' => $group->owner_id,
            'idempotency_key' => (string) Str::uuid(),
            'parameters' => Crypt::encryptString(json_encode(['metadata' => ['user_id' => (string) $group->owner_id]], JSON_THROW_ON_ERROR)),
            'started_at' => now(),
        ]);
        $this->assertDatabaseHas('owner_connect_attempts', ['user_id' => $group->owner_id]);
    }
}
