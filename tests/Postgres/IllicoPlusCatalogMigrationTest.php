<?php

namespace Tests\Postgres;

use Illuminate\Support\Facades\DB;

final class IllicoPlusCatalogMigrationTest extends PostgresTestCase
{
    public function test_adds_illico_plus_once_to_an_existing_production_catalogue(): void
    {
        $streaming = DB::table('subscription_categories')->insertGetId([
            'name' => 'Streaming', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_11_09_120000_add_illico_plus_to_catalog.php');

        $migration->up();
        $migration->up();

        $offer = DB::table('subscriptions')->where('slug', 'illico')->sole();
        $this->assertSame('illico+', $offer->name);
        $this->assertSame($streaming, (int) $offer->category_id);
        $this->assertSame(1500, (int) $offer->monthly_price);
        $this->assertSame(2, (int) $offer->max_members);
        $this->assertSame('CAD', $offer->currency);
        $this->assertSame('credentials', $offer->access_mode);
        $this->assertTrue((bool) $offer->is_active);
    }
}
