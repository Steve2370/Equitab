<?php

namespace Tests\Feature;

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
use Tests\Support\BillingTestCase;

class GroupCurrencyMigrationTest extends BillingTestCase
{
    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_07_180000_add_native_currency_to_groups.php');
    }

    public function test_upgrade_preserves_legacy_amounts_and_uses_financial_currency_over_catalogue(): void
    {
        $group = Group::factory()->create(['total_price' => 1703]);
        $payment = Payment::factory()->for($group)->create(['currency' => 'CAD', 'amount' => 851]);
        $beforePayment = DB::table('payments')->where('id', $payment->id)->first();
        $this->migration()->down();
        $group->subscription->update(['currency' => 'EUR']);
        $beforeGroup = DB::table('groups')->where('id', $group->id)->first();

        $this->migration()->up();

        $this->assertSame('CAD', $group->fresh()->currency);
        $this->assertEquals($beforePayment, DB::table('payments')->where('id', $payment->id)->first());
        $afterGroup = (array) DB::table('groups')->where('id', $group->id)->first();
        unset($afterGroup['currency']);
        $this->assertSame((array) $beforeGroup, $afterGroup);
    }

    public function test_encrypted_in_flight_attempt_is_a_currency_source_without_rewriting_it(): void
    {
        $group = Group::factory()->create();
        $this->migration()->down();
        $encrypted = Crypt::encryptString(json_encode(['price' => ['unit_amount' => 901, 'currency' => 'eur']], JSON_THROW_ON_ERROR));
        $attemptId = (string) Str::uuid();
        DB::table('subscription_attempts')->insert([
            'id' => $attemptId, 'group_id' => $group->id, 'user_id' => User::factory()->create()->id,
            'parameters' => $encrypted, 'started_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame('EUR', $group->fresh()->currency);
        $this->assertSame($encrypted, DB::table('subscription_attempts')->where('id', $attemptId)->value('parameters'));
    }

    public function test_conflicting_financial_history_aborts_the_entire_migration(): void
    {
        $group = Group::factory()->create();
        Payment::factory()->for($group)->create(['currency' => 'CAD']);
        StripePrice::create(['group_id' => $group->id, 'stripe_product_id' => 'prod_conflict',
            'stripe_price_id' => null, 'unit_amount' => 1900, 'currency' => 'EUR']);
        $this->migration()->down();
        $before = DB::table('groups')->get()->toArray();

        try {
            $this->migration()->up();
            $this->fail('A conflicting history cannot be assigned a guessed currency.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('à rapprocher', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('groups', 'currency'));
        $this->assertEquals($before, DB::table('groups')->get()->toArray());
        $this->assertSame('CAD', Payment::sole()->currency);
        $this->assertSame('EUR', StripePrice::sole()->currency);
    }

    public function test_downgrade_refuses_to_erase_an_eur_group_contract(): void
    {
        $group = Group::factory()->create(['currency' => 'EUR']);
        try {
            $this->migration()->down();
            $this->fail('EUR contracts must survive a rollback request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Retour arrière refusé', $exception->getMessage());
        }
        $this->assertSame('EUR', $group->fresh()->currency);
    }

    public function test_empty_schema_supports_down_and_up_without_a_default_currency(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('groups', 'currency'));
        $this->migration()->up();
        $column = collect(Schema::getColumns('groups'))->firstWhere('name', 'currency');
        $this->assertFalse($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertDatabaseCount('groups', 0);
    }
}
