<?php

namespace Tests\Support;

use App\Models\Group;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

trait InvitationCatalogueMigrationChecks
{
    public function test_catalogue_upgrade_and_safe_rollback_preserve_legacy_values_and_groups(): void
    {
        // Reconstruct a legacy catalogue before testing the earlier schema migration.
        // The later release's nullable invitation offers deliberately prevent rollback.
        Subscription::whereIn('slug', ['dropbox-family', 'nordpass-family'])->delete();
        $migration = require database_path('migrations/2026_10_08_000100_prepare_invitation_catalog.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('subscriptions', 'access_mode'));
        $subscription = Subscription::factory()->create(['monthly_price' => 2345, 'billing_cycle' => 'yearly']);
        $group = Group::factory()->create(['subscription_id' => $subscription->id, 'total_price' => 6789]);
        $before = (array) DB::table('subscriptions')->find($subscription->id);
        $groupBefore = (array) DB::table('groups')->find($group->id);

        $migration->up();
        $after = (array) DB::table('subscriptions')->find($subscription->id);
        $this->assertSame('credentials', $after['access_mode']);
        unset($after['access_mode']);
        $this->assertSame($before, $after);
        $this->assertSame($groupBefore, (array) DB::table('groups')->find($group->id));
        $migration->down();
        $this->assertSame($before, (array) DB::table('subscriptions')->find($subscription->id));
        $migration->up();
        $this->assertSame($groupBefore, (array) DB::table('groups')->find($group->id));
        $this->assertSame(2345, $subscription->fresh()->monthly_price);
        $this->assertSame('yearly', $subscription->fresh()->billing_cycle);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        }
    }

    public function test_unknown_prices_and_supplier_cycles_remain_null_without_a_fabricated_monthly_default(): void
    {
        $subscription = Subscription::factory()->create(['monthly_price' => null, 'billing_cycle' => null]);
        $this->assertNull($subscription->fresh()->monthly_price);
        $this->assertNull($subscription->fresh()->price_in_dollars);
        $this->assertNull($subscription->fresh()->billing_cycle);
        $defaults = $subscription->replicate();
        unset($defaults->billing_cycle);
        $defaults->slug = 'defaults-unknown-cycle';
        $defaults->save();
        $this->assertNull($defaults->fresh()->billing_cycle);
    }

    public function test_rollback_refuses_each_incompatible_value_without_altering_catalogue_or_groups(): void
    {
        Subscription::whereIn('slug', ['dropbox-family', 'nordpass-family'])->delete();
        $migration = require database_path('migrations/2026_10_08_000100_prepare_invitation_catalog.php');
        foreach ([['monthly_price' => null], ['billing_cycle' => null], ['access_mode' => 'invitation']] as $attributes) {
            $subscription = Subscription::factory()->create($attributes);
            $before = (array) DB::table('subscriptions')->find($subscription->id);
            try {
                $migration->down();
                $this->fail('Rollback must not fabricate prices/cycles or lose invitation semantics.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Retour arrière refusé', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('subscriptions', 'access_mode'));
            $this->assertSame($before, (array) DB::table('subscriptions')->find($subscription->id));
            $subscription->delete();
        }
    }

    public function test_supplier_cycle_constraint_still_rejects_unrecognized_values(): void
    {
        $subscription = Subscription::factory()->create(['billing_cycle' => 'yearly']);
        try {
            DB::transaction(fn () => DB::table('subscriptions')->where('id', $subscription->id)
                ->update(['billing_cycle' => 'invented-cycle']));
            $this->fail('Migration must preserve the supplier cycle constraint.');
        } catch (QueryException $exception) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? '23514' : '23000', $exception->errorInfo[0]);
        }
        $this->assertSame('yearly', $subscription->fresh()->billing_cycle);
    }
}
