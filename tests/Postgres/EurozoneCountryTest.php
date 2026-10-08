<?php

namespace Tests\Postgres;

use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class EurozoneCountryTest extends PostgresTestCase
{
    public function test_country_change_cannot_race_an_in_flight_connect_account(): void
    {
        $owner = User::factory()->create(['country' => 'BE']);
        $creation = $this->worker('be-creation', 'connect', $owner, null, ['eurozone' => true, 'pause' => 'account']);
        $this->awaitSignal('account:be-creation');
        $attempt = DB::table('owner_connect_attempts')->sole();
        $this->assertSame(422, $this->workerResult($this->worker('late-fr', 'country', $owner, null, ['country' => 'FR']))['status']);
        $this->assertSame('BE', $owner->fresh()->country);
        $this->release('be-creation');
        $this->assertSame(200, $this->workerResult($creation)['status']);
        $this->assertSame('BE', json_decode(Crypt::decryptString($attempt->parameters), true)['country']);
        $this->assertDatabaseCount('qa_accounts', 1);
    }

    public function test_committed_country_change_is_used_by_a_waiting_creator(): void
    {
        $owner = User::factory()->create(['country' => 'CA']);
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail()->update(['country' => 'BE']);
        $creation = $this->worker('waiting-be', 'connect', $owner, null, ['eurozone' => true]);
        $this->await(function (): bool {
            DB::select('SELECT pg_stat_clear_snapshot()');

            return (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity
                WHERE application_name = 'equitab-qa-waiting-be' AND wait_event_type = 'Lock'")->count === 1;
        }, 'Connect must wait for the profile row lock.');
        DB::commit();
        $this->assertSame(200, $this->workerResult($creation)['status']);
        $this->assertSame('BE', json_decode(DB::table('qa_accounts')->sole()->parameters, true)['country']);
    }

    public function test_eurozone_lost_response_recovers_when_rollout_is_disabled(): void
    {
        $owner = User::factory()->create(['country' => 'BE']);
        $this->assertSame(503, $this->workerResult($this->worker('be-lost', 'connect', $owner, null, ['eurozone' => true, 'lost_response' => true]))['status']);
        $attempt = DB::table('owner_connect_attempts')->sole();
        $this->assertSame(200, $this->workerResult($this->worker('be-resume', 'connect', $owner))['status']);
        $this->assertSame($attempt->parameters, DB::table('owner_connect_attempts')->sole()->parameters);
        $this->assertSame($attempt->idempotency_key, DB::table('owner_connect_attempts')->sole()->idempotency_key);
        $this->assertDatabaseCount('qa_accounts', 1);
    }

    public function test_waiting_profile_update_cannot_restore_a_previous_countrys_address(): void
    {
        $owner = User::factory()->create(['country' => 'CA', 'address' => 'Old address']);
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail()->update(['country' => 'BE', 'address' => null]);
        $save = $this->worker('stale-address', 'profile', $owner, null, [
            'data' => ['expected_country' => 'CA', 'address' => 'Old address'],
        ]);
        $this->await(function (): bool {
            DB::select('SELECT pg_stat_clear_snapshot()');

            return (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity
                WHERE application_name = 'equitab-qa-stale-address' AND wait_event_type = 'Lock'")->count === 1;
        }, 'Profile save must wait for the country row lock.');
        DB::commit();
        $this->assertSame(422, $this->workerResult($save)['status']);
        $this->assertSame('BE', $owner->fresh()->country);
        $this->assertNull($owner->fresh()->address);
    }

    public function test_migration_preserves_existing_accounts_and_financial_history(): void
    {
        $group = Group::factory()->create();
        Payment::factory()->for($group)->create();
        $tables = ['users', 'groups', 'payments'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        $migration = require database_path('migrations/2026_10_07_190000_add_owner_country_to_users.php');
        $migration->down();
        $migration->up();
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->toArray());
        }
        $owner = $group->owner;
        $owner->update(['country' => 'BE', 'province' => 'Bruxelles-Capitale', 'postal_code' => '1000']);
        $this->assertSame('Bruxelles-Capitale', $owner->fresh()->province);
        try {
            $migration->down();
            $this->fail('A populated country must not be erased.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::hasColumn('users', 'country'));
            $this->assertSame('BE', $owner->fresh()->country);
        }
    }
}
