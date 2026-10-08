<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BillingTestCase;

class EurozoneCountryMigrationTest extends BillingTestCase
{
    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_07_190000_add_owner_country_to_users.php');
    }

    public function test_empty_schema_can_upgrade_rollback_and_upgrade_again_without_a_default_country(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('users', 'country'));
        $this->migration()->up();
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'country');
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(User::factory()->create()->country);
    }

    public function test_upgrade_never_guesses_country_and_preserves_users_related_data_and_constraints(): void
    {
        $this->migration()->down();
        $canadian = User::factory()->create([
            'email' => 'canadian@example.test', 'stripe_connect_account_id' => 'acct_legacy_ca',
            'address' => '1 rue Exemple', 'city' => 'Montréal', 'province' => 'QC', 'postal_code' => 'H2X 1Y4',
            'currency' => 'CAD', 'locale' => 'fr',
        ]);
        $european = User::factory()->create([
            'email' => 'european@example.test', 'city' => 'Bruxelles', 'postal_code' => '1000',
            'currency' => 'EUR', 'locale' => 'fr', 'timezone' => 'Europe/Brussels',
        ]);
        $this->seedDependants($canadian, $european);
        $before = $this->snapshot();
        $foreignKeys = Schema::getForeignKeys('group_members');

        $this->migration()->up();

        $this->assertSame($before, $this->snapshot());
        $this->assertNull($canadian->fresh()->country);
        $this->assertNull($european->fresh()->country);
        $this->assertSame($foreignKeys, Schema::getForeignKeys('group_members'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('acct_legacy_ca', $canadian->fresh()->stripe_connect_account_id);
        $this->assertSame('legacy-encrypted-fixture', Group::sole()->credential_password);
    }

    public function test_safe_rollback_and_reapply_preserve_existing_rows_and_child_rows(): void
    {
        $owner = User::factory()->create(['province' => 'QC', 'postal_code' => 'H2X 1Y4']);
        $member = User::factory()->create();
        $this->seedDependants($owner, $member);
        $before = $this->snapshot();

        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('users', 'country'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
        $this->assertNull($owner->fresh()->country);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
        $this->assertDatabaseCount('group_members', 2);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public static function destructiveRollbacks(): array
    {
        return [
            'declared Canada' => [['country' => 'CA']],
            'declared Belgium' => [['country' => 'BE']],
            'region would be truncated' => [['province' => 'Bruxelles-Capitale']],
            'postal would be truncated' => [['postal_code' => '12345678901']],
            'full new address widths' => [['province' => str_repeat('r', 100), 'postal_code' => str_repeat('1', 20)]],
        ];
    }

    #[DataProvider('destructiveRollbacks')]
    public function test_rollback_refuses_to_erase_country_or_truncate_address_before_any_schema_change(array $attributes): void
    {
        $owner = User::factory()->create($attributes);
        $member = User::factory()->create();
        $this->seedDependants($owner, $member);
        $rows = $this->snapshot();
        $ownerBefore = $owner->fresh()->getRawOriginal();
        $schema = Schema::getColumns('users');

        try {
            $this->migration()->down();
            $this->fail('Rollback must refuse country/address data loss.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'country'));
        $this->assertSame($schema, Schema::getColumns('users'));
        $this->assertSame($ownerBefore, $owner->fresh()->getRawOriginal());
        $this->assertSame($rows, $this->snapshot());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_extended_region_and_postal_round_trip_with_country_without_overwriting_legacy_addresses(): void
    {
        $legacy = User::factory()->create(['province' => 'QC', 'postal_code' => 'H2X 1Y4']);
        $before = $legacy->fresh()->getRawOriginal();
        $european = User::factory()->create([
            'country' => 'BE', 'province' => str_repeat('r', 100), 'postal_code' => str_repeat('1', 20),
        ]);
        $this->assertSame('BE', $european->fresh()->country);
        $this->assertSame(str_repeat('r', 100), $european->fresh()->province);
        $this->assertSame(str_repeat('1', 20), $european->fresh()->postal_code);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
    }

    public function test_upgrade_and_safe_rollback_preserve_partial_email_index_and_re_registration(): void
    {
        $this->migration()->down();
        $old = User::factory()->create(['email' => 'reusable@example.test']);
        $old->delete();
        $current = User::factory()->create(['email' => $old->email]);
        $this->seedDependants($current, User::factory()->create());
        $sql = DB::table('sqlite_master')->where('type', 'index')->where('name', 'users_email_unique')->value('sql');
        $this->assertIsString($sql);
        $this->assertMatchesRegularExpression('/WHERE\s+deleted_at\s+IS\s+NULL/i', $sql);
        $before = $this->snapshot();

        foreach (['up', 'down', 'up'] as $operation) {
            $this->migration()->{$operation}();
            $this->assertSame($sql, DB::table('sqlite_master')->where('type', 'index')->where('name', 'users_email_unique')->value('sql'));
            $this->assertSame($before, $this->snapshot());
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        }

        // Verify the predicate's behavior, not just its text: another deleted
        // historical identity must not prevent re-registration after upgrade.
        $current->delete();
        $latest = User::factory()->create(['email' => $old->email]);
        $this->assertSame(3, User::withTrashed()->where('email', $old->email)->count());
        $this->assertSame(1, User::where('email', $old->email)->count());
        try {
            User::factory()->create(['email' => $latest->email]);
            $this->fail('The partial index must still refuse duplicate active identities.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }
        $this->assertSame(1, User::where('email', $old->email)->count());
    }

    private function seedDependants(User $owner, User $member): void
    {
        $group = Group::factory()->create([
            'owner_id' => $owner->id, 'currency' => 'CAD', 'total_price' => 1703,
            'credential_password' => 'legacy-encrypted-fixture',
        ]);
        GroupMember::factory()->owner()->create(['group_id' => $group->id, 'user_id' => $owner->id]);
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);
        Payment::factory()->create(['group_id' => $group->id, 'user_id' => $member->id, 'amount' => 851, 'currency' => 'CAD']);
        $draft = new GroupDraft;
        $draft->forceFill(['owner_id' => $owner->id, 'data' => ['name' => 'Brouillon à préserver', 'currency' => 'EUR']])->save();
        DB::table('owner_connect_attempts')->insert([
            'user_id' => $member->id, 'idempotency_key' => (string) Str::uuid(),
            'parameters' => Crypt::encryptString(json_encode(['country' => 'CA', 'email' => $member->email], JSON_THROW_ON_ERROR)),
            'started_at' => now(),
        ]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'subscriptions', 'groups', 'group_members', 'payments', 'group_drafts', 'owner_connect_attempts'] as $table) {
            $key = $table === 'owner_connect_attempts' ? 'user_id' : 'id';
            $snapshot[$table] = DB::table($table)->orderBy($key)->get()->map(function ($row) use ($table): array {
                $attributes = (array) $row;
                if ($table === 'users') {
                    unset($attributes['country']);
                }

                return $attributes;
            })->all();
        }

        return $snapshot;
    }
}
