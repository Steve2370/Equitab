<?php

namespace Tests\Postgres;

use App\Models\Group;
use App\Models\Payment;
use App\Models\StripePrice;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CurrencyMigrationTest extends PostgresTestCase
{
    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_07_180000_add_native_currency_to_groups.php');
    }

    public function test_native_postgres_upgrade_keeps_history_and_in_flight_eur_attempts(): void
    {
        $cad = Group::factory()->create(['total_price' => 2101]);
        $eur = Group::factory()->create(['total_price' => 1803]);
        $payment = Payment::factory()->for($cad)->create(['amount' => 701, 'currency' => 'CAD']);
        $this->migration()->down();
        $cad->subscription->update(['currency' => 'EUR']);
        $parameters = Crypt::encryptString(json_encode(['price' => ['currency' => 'eur', 'unit_amount' => 901]], JSON_THROW_ON_ERROR));
        DB::table('subscription_attempts')->insert([
            'id' => (string) Str::uuid(), 'group_id' => $eur->id, 'user_id' => User::factory()->create()->id,
            'parameters' => $parameters, 'started_at' => now(),
        ]);
        $beforePayments = DB::table('payments')->orderBy('id')->get()->toArray();
        $beforeAttempts = DB::table('subscription_attempts')->get()->toArray();

        $this->migration()->up();

        $this->assertSame('CAD', $cad->fresh()->currency);
        $this->assertSame('EUR', $eur->fresh()->currency);
        $this->assertSame(2101, $cad->fresh()->total_price);
        $this->assertSame(1803, $eur->fresh()->total_price);
        $this->assertSame(701, $payment->fresh()->amount);
        $this->assertEquals($beforePayments, DB::table('payments')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeAttempts, DB::table('subscription_attempts')->get()->toArray());
        $column = collect(Schema::getColumns('groups'))->firstWhere('name', 'currency');
        $this->assertFalse($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_conflicting_group_rolls_back_ddl_and_all_preceding_backfills(): void
    {
        $first = Group::factory()->create();
        $second = Group::factory()->create();
        Payment::factory()->for($second)->create(['currency' => 'EUR']);
        StripePrice::create(['group_id' => $second->id, 'stripe_product_id' => 'prod_mixed_pg',
            'stripe_price_id' => null, 'unit_amount' => 1999, 'currency' => 'CAD']);
        $this->migration()->down();
        $before = DB::table('groups')->orderBy('id')->get()->toArray();

        try {
            $this->migration()->up();
            $this->fail('Mixed historical currencies must not be guessed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('#'.$second->id, $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('groups', 'currency'));
        $this->assertEquals($before, DB::table('groups')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('groups', ['id' => $first->id]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_rollback_refuses_to_remove_an_eur_contract_even_after_archiving(): void
    {
        $group = Group::factory()->create(['currency' => 'EUR']);
        $group->delete();
        try {
            $this->migration()->down();
            $this->fail('Archived financial contracts must remain recoverable.');
        } catch (RuntimeException) {
            $this->assertSame('EUR', Group::withTrashed()->findOrFail($group->id)->currency);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_empty_schema_can_be_rolled_back_and_reapplied(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('groups', 'currency'));
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('groups', 'currency'));
        $this->assertDatabaseCount('groups', 0);
    }
}
