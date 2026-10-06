<?php

namespace Tests\Postgres;

use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\StripePrice;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class OwnerMigrationTest extends PostgresTestCase
{
    private const MIGRATIONS = [
        'group_drafts' => '2026_10_06_120000_create_group_drafts_table.php',
        'owner_connect_attempts' => '2026_10_06_120100_create_owner_connect_attempts_table.php',
    ];

    protected function setUp(): void
    {
        // PostgresTestCase owns the local disposable database guard and migrate:fresh.
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public static function migrationScopes(): array
    {
        return [
            'drafts only' => [['group_drafts']],
            'Connect attempts only' => [['owner_connect_attempts']],
            'both new migrations' => [['group_drafts', 'owner_connect_attempts']],
        ];
    }

    #[DataProvider('migrationScopes')]
    public function test_up_down_and_reapply_preserve_existing_schema_and_data(array $tables): void
    {
        $group = $this->seedLegacyGroupWithMembersAndPayments();
        $migrations = [];
        $definitions = [];

        foreach (self::MIGRATIONS as $table => $filename) {
            $this->assertDatabaseCount($table, 0);
            $this->populateNewTable($table, $group);

            if (in_array($table, $tables, true)) {
                $migration = require database_path('migrations/'.$filename);
                $this->assertInstanceOf(Migration::class, $migration);
                $migrations[$table] = $migration;
                $definitions[$table] = $this->tableDefinition($table);
            }
        }

        // A migration exercised alone must also preserve the other populated new table.
        $before = $this->preservedSnapshot($tables);

        // Reconstruct the old schema using only the explicitly authorized new down() methods.
        foreach (array_reverse($migrations, true) as $table => $migration) {
            $migration->down();
            $this->assertFalse(Schema::hasTable($table));
            $this->assertSame($before, $this->preservedSnapshot($tables), "Initial rollback of {$table} changed existing schema or data.");
        }

        foreach ($migrations as $table => $migration) {
            $migration->up();
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame($definitions[$table], $this->tableDefinition($table));
            $this->assertDatabaseCount($table, 0);
            $this->populateNewTable($table, $group);
            $this->assertSame($before, $this->preservedSnapshot($tables), "Upgrade of {$table} changed existing schema or data.");
        }

        foreach (array_reverse($migrations, true) as $table => $migration) {
            $migration->down();
            $this->assertFalse(Schema::hasTable($table));
            $this->assertSame($before, $this->preservedSnapshot($tables), "Rollback of populated {$table} changed existing schema or data.");
        }

        foreach ($migrations as $table => $migration) {
            $migration->up();
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame($definitions[$table], $this->tableDefinition($table));
            $this->assertDatabaseCount($table, 0);
            $this->populateNewTable($table, $group);
            $this->assertSame($before, $this->preservedSnapshot($tables), "Reapplying {$table} changed existing schema or data.");
        }

        $this->assertSame('legacy-encrypted-password-949', $group->fresh()->credential_password);
        $this->assertSame(2, $group->fresh()->members()->where('status', 'active')->count());
        $this->assertSame(2, $group->fresh()->payments()->count());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_drafts_use_native_uuids_defaults_json_and_restorable_soft_deletes(): void
    {
        $owner = $this->readyOwner();
        $data = ['name' => 'Brouillon québécois', 'nested' => ['enabled' => true, 'amount' => 1999, 'optional' => null]];
        $draft = GroupDraft::create(['owner_id' => $owner->id, 'data' => $data])->refresh();

        $this->assertSame('uuid', Schema::getColumnType('group_drafts', 'id'));
        $this->assertTrue(Str::isUuid($draft->id));
        $this->assertSame($data, $draft->data);
        $this->assertSame(1, $draft->version);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->published_group_id);
        $this->assertNull($draft->deleted_at);
        $this->assertNotNull($draft->created_at);
        $this->assertNotNull($draft->updated_at);

        $draft->delete();

        $this->assertSoftDeleted('group_drafts', ['id' => $draft->id]);
        $this->assertNull(GroupDraft::find($draft->id));
        $deleted = GroupDraft::withTrashed()->findOrFail($draft->id);
        $this->assertSame($data, $deleted->data);
        $this->assertNotNull($deleted->deleted_at);
        $deleted->restore();

        $restored = GroupDraft::findOrFail($draft->id);
        $this->assertSame($data, $restored->data);
        $this->assertSame(1, $restored->version);
        $this->assertNull($restored->deleted_at);
        $this->assertDatabaseCount('group_drafts', 1);
    }

    public function test_multiple_unpublished_drafts_can_have_a_null_published_group_id(): void
    {
        $owner = $this->readyOwner();
        $first = $this->draftFor($owner);
        $second = $this->draftFor($owner);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($first->fresh()->published_group_id);
        $this->assertNull($second->fresh()->published_group_id);
        $this->assertSame(2, DB::table('group_drafts')->whereNull('published_group_id')->count());
    }

    public static function publishedDraftStates(): array
    {
        return [
            'active published draft' => [false],
            'soft-deleted published draft' => [true],
        ];
    }

    #[DataProvider('publishedDraftStates')]
    public function test_a_published_group_cannot_be_referenced_by_another_draft(bool $softDeleted): void
    {
        $owner = $this->readyOwner();
        $group = Group::factory()->create(['owner_id' => $owner->id]);
        $first = $this->draftFor($owner);
        $first->update(['status' => 'published', 'published_group_id' => $group->id]);
        if ($softDeleted) {
            $first->delete();
        }
        $second = $this->draftFor($owner);

        $this->assertRejectedWrite('23505', fn () => DB::table('group_drafts')->where('id', $second->id)->update([
            'status' => 'published', 'published_group_id' => $group->id,
        ]));

        $this->assertSame(1, DB::table('group_drafts')->where('published_group_id', $group->id)->count());
        $this->assertNull($second->fresh()->published_group_id);
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame($softDeleted, GroupDraft::withTrashed()->findOrFail($first->id)->trashed());
        $this->assertDatabaseCount('group_drafts', 2);
    }

    #[DataProvider('publishedDraftStates')]
    public function test_a_referenced_group_cannot_be_physically_deleted(bool $softDeleted): void
    {
        $owner = $this->readyOwner();
        // No members or payments: a rejection must come from the new draft foreign key.
        $group = Group::factory()->create(['owner_id' => $owner->id]);
        $draft = $this->draftFor($owner);
        $draft->update(['status' => 'published', 'published_group_id' => $group->id]);
        if ($softDeleted) {
            $draft->delete();
        }

        $this->assertRejectedWrite('23503', fn () => DB::table('groups')->where('id', $group->id)->delete());

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
        $this->assertDatabaseHas('group_drafts', ['id' => $draft->id, 'published_group_id' => $group->id]);

        $draft->forceDelete();
        $this->assertSame(1, DB::table('groups')->where('id', $group->id)->delete());
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }

    public function test_deleting_an_owner_cascades_only_their_drafts_and_connect_attempt(): void
    {
        $owner = $this->readyOwner();
        $otherOwner = $this->readyOwner();
        $this->draftFor($owner);
        $deleted = $this->draftFor($owner);
        $deleted->delete();
        $otherDraft = $this->draftFor($otherOwner);
        DB::table('owner_connect_attempts')->insert($this->attemptData($owner));
        DB::table('owner_connect_attempts')->insert($this->attemptData($otherOwner));
        $otherDraftBefore = (array) DB::table('group_drafts')->where('id', $otherDraft->id)->first();
        $otherAttemptBefore = (array) DB::table('owner_connect_attempts')->where('user_id', $otherOwner->id)->first();

        $this->assertSame(1, DB::table('users')->where('id', $owner->id)->delete());

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('group_drafts', ['owner_id' => $owner->id]);
        $this->assertDatabaseMissing('owner_connect_attempts', ['user_id' => $owner->id]);
        $this->assertSame($otherDraftBefore, (array) DB::table('group_drafts')->where('id', $otherDraft->id)->first());
        $this->assertSame($otherAttemptBefore, (array) DB::table('owner_connect_attempts')->where('user_id', $otherOwner->id)->first());
        $this->assertDatabaseHas('users', ['id' => $otherOwner->id]);
    }

    public static function missingForeignKeys(): array
    {
        return [
            'draft owner' => ['group_drafts', 'owner_id'],
            'published group' => ['group_drafts', 'published_group_id'],
            'Connect owner' => ['owner_connect_attempts', 'user_id'],
        ];
    }

    #[DataProvider('missingForeignKeys')]
    public function test_foreign_keys_reject_missing_parents(string $table, string $column): void
    {
        $owner = $this->readyOwner();
        $parent = $column === 'published_group_id' ? 'groups' : 'users';
        $missingId = (int) DB::table($parent)->max('id') + 1;
        $this->assertDatabaseMissing($parent, ['id' => $missingId]);
        $row = $table === 'group_drafts' ? $this->draftRow($owner) : $this->attemptData($owner);
        $row[$column] = $missingId;

        $this->assertRejectedWrite('23503', fn () => DB::table($table)->insert($row));

        $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public static function nativeUuidColumns(): array
    {
        return [
            'draft UUID' => ['group_drafts', 'id'],
            'Connect idempotency UUID' => ['owner_connect_attempts', 'idempotency_key'],
        ];
    }

    #[DataProvider('nativeUuidColumns')]
    public function test_postgres_rejects_malformed_uuids(string $table, string $column): void
    {
        $owner = $this->readyOwner();
        $row = $table === 'group_drafts' ? $this->draftRow($owner) : $this->attemptData($owner);
        $row[$column] = 'not-a-valid-uuid';
        $this->assertSame('uuid', Schema::getColumnType($table, $column));

        $this->assertRejectedWrite('22P02', fn () => DB::table($table)->insert($row));

        $this->assertDatabaseCount($table, 0);
    }

    public function test_draft_uuid_is_a_unique_primary_key(): void
    {
        $owner = $this->readyOwner();
        $row = $this->draftRow($owner);
        DB::table('group_drafts')->insert($row);
        $before = (array) DB::table('group_drafts')->where('id', $row['id'])->first();

        $this->assertRejectedWrite('23505', fn () => DB::table('group_drafts')->insert($row));

        $this->assertDatabaseCount('group_drafts', 1);
        $this->assertSame($before, (array) DB::table('group_drafts')->where('id', $row['id'])->first());
    }

    public static function uniqueAttemptColumns(): array
    {
        return [
            'one attempt per owner' => ['user_id'],
            'idempotency key unique across owners' => ['idempotency_key'],
        ];
    }

    #[DataProvider('uniqueAttemptColumns')]
    public function test_connect_attempt_uniqueness_preserves_the_original_attempt(string $column): void
    {
        $owner = $this->readyOwner();
        $otherOwner = $this->readyOwner();
        $first = $this->attemptData($owner);
        DB::table('owner_connect_attempts')->insert($first);
        $before = (array) DB::table('owner_connect_attempts')->where('user_id', $owner->id)->first();
        $second = $this->attemptData($otherOwner);
        $second[$column] = $first[$column];

        $this->assertRejectedWrite('23505', fn () => DB::table('owner_connect_attempts')->insert($second));

        $this->assertDatabaseCount('owner_connect_attempts', 1);
        $this->assertSame($before, (array) DB::table('owner_connect_attempts')->where('user_id', $owner->id)->first());
        $this->assertDatabaseMissing('owner_connect_attempts', ['user_id' => $otherOwner->id]);
    }

    private function assertRejectedWrite(string $sqlState, callable $write): void
    {
        // No enclosing transaction: PostgreSQL constraint failures leave later assertions usable.
        $this->assertSame(0, DB::connection()->transactionLevel());

        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);

            return;
        }

        $this->fail("PostgreSQL accepted a write that should fail with SQLSTATE {$sqlState}.");
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
        GroupMember::factory()->withStripeSubscription('sub_legacy_pg_migration')->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'share_amount' => 1200,
            'stripe_subscription_item_id' => 'si_legacy_pg_migration',
        ]);
        Payment::factory()->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'amount' => 1200,
            'platform_fee_amount' => 100, 'stripe_payment_intent_id' => 'pi_legacy_pg_completed',
        ]);
        Payment::factory()->refunded()->create([
            'group_id' => $group->id, 'user_id' => $payer->id, 'amount' => 1200,
            'platform_fee_amount' => 100, 'stripe_payment_intent_id' => 'pi_legacy_pg_refunded',
            'stripe_refund_id' => 're_legacy_pg_migration',
        ]);
        StripePrice::create([
            'group_id' => $group->id, 'stripe_product_id' => 'prod_legacy_pg_migration',
            'stripe_price_id' => 'price_legacy_pg_migration', 'unit_amount' => 2400, 'currency' => 'CAD',
        ]);

        return $group;
    }

    private function tableDefinition(string $table): array
    {
        return [
            'columns' => Schema::getColumns($table),
            'indexes' => collect(Schema::getIndexes($table))->sortBy('name')->values()->all(),
            'foreign_keys' => collect(Schema::getForeignKeys($table))->sortBy('name')->values()->all(),
        ];
    }

    private function preservedSnapshot(array $migratedTables): array
    {
        $tables = array_diff([
            'users', 'subscriptions', 'groups', 'group_members', 'payments', 'stripe_prices', 'migrations',
            ...array_keys(self::MIGRATIONS),
        ], $migratedTables);
        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = [
                'schema' => $this->tableDefinition($table),
                'rows' => DB::table($table)->orderBy($table === 'owner_connect_attempts' ? 'user_id' : 'id')
                    ->get()->map(fn ($row) => (array) $row)->all(),
            ];
        }

        return $snapshot;
    }

    private function populateNewTable(string $table, Group $group): void
    {
        if ($table === 'group_drafts') {
            $draft = GroupDraft::create(['owner_id' => $group->owner_id, 'data' => ['name' => 'Après migration']])->refresh();
            $this->assertTrue(Str::isUuid($draft->id));
            $this->assertSame(1, $draft->version);
            $this->assertSame('draft', $draft->status);
            $this->assertSame(['name' => 'Après migration'], $draft->data);
            $this->assertNull($draft->published_group_id);
            $draft->update(['status' => 'published', 'published_group_id' => $group->id]);
            $draft->delete();
            $this->assertSoftDeleted('group_drafts', ['id' => $draft->id, 'published_group_id' => $group->id]);

            return;
        }

        // Storage round-trip only; account creation and its Stripe boundary belong to other tests.
        $row = $this->attemptData($group->owner);
        DB::table('owner_connect_attempts')->insert($row);
        $stored = DB::table('owner_connect_attempts')->where('user_id', $group->owner_id)->first();
        $this->assertNotNull($stored);
        $this->assertSame($row['idempotency_key'], $stored->idempotency_key);
        $this->assertSame($row['started_at'], $stored->started_at);
        $this->assertSame($row['parameters'], $stored->parameters);
        $this->assertGreaterThan(255, strlen($stored->parameters));
        $this->assertStringNotContainsString('migration-owner@example.test', $stored->parameters);
        $this->assertSame($this->attemptParameters($group->owner), json_decode(Crypt::decryptString($stored->parameters), true, flags: JSON_THROW_ON_ERROR));
    }

    private function draftRow(User $owner): array
    {
        return [
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'data' => json_encode(['name' => 'Brouillon PostgreSQL'], JSON_THROW_ON_ERROR),
        ];
    }

    private function attemptData(User $owner): array
    {
        return [
            'user_id' => $owner->id,
            'idempotency_key' => (string) Str::uuid(),
            'parameters' => Crypt::encryptString(json_encode($this->attemptParameters($owner), JSON_THROW_ON_ERROR)),
            'started_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function attemptParameters(User $owner): array
    {
        return [
            'type' => 'express',
            'country' => 'CA',
            'email' => 'migration-owner@example.test',
            'metadata' => ['user_id' => (string) $owner->id, 'label' => str_repeat('Migration québécoise ', 24)],
        ];
    }
}
