<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IllicoPlusCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_09_120000_add_illico_plus_to_catalog.php');
    }

    public function test_adds_illico_plus_to_an_existing_streaming_catalogue(): void
    {
        $streaming = SubscriptionCategory::create(['name' => 'Streaming']);

        $this->migration()->up();

        $offer = Subscription::where('slug', 'illico')->sole();
        $this->assertSame('illico+', $offer->name);
        $this->assertSame($streaming->id, $offer->category_id);
        $this->assertSame(1500, $offer->monthly_price);
        $this->assertSame(2, $offer->max_members);
        $this->assertSame('CAD', $offer->currency);
        $this->assertSame('monthly', $offer->billing_cycle);
        $this->assertSame('credentials', $offer->access_mode);
        $this->assertTrue($offer->is_active);
        $this->assertNull($offer->logo);
    }

    public function test_is_idempotent_and_never_overwrites_operator_settings(): void
    {
        SubscriptionCategory::create(['name' => 'Streaming']);
        $this->migration()->up();
        Subscription::where('slug', 'illico')->update(['monthly_price' => 1700, 'is_active' => false]);

        $this->migration()->up();

        $offer = Subscription::where('slug', 'illico')->sole();
        $this->assertSame(1700, $offer->monthly_price);
        $this->assertFalse($offer->is_active);
    }

    public function test_fresh_install_without_catalogue_is_left_to_the_seeder(): void
    {
        $before = Subscription::count();

        $this->migration()->up();

        $this->assertSame($before, Subscription::count());
        $this->seed(\Database\Seeders\SubscriptionSeeder::class);
        $offer = Subscription::where('name', 'illico+')->sole();
        $this->assertSame('illico', $offer->slug);
        $this->assertSame(2, $offer->max_members);
        $this->assertSame(1500, $offer->monthly_price);
    }

    public function test_ambiguous_catalogue_identity_changes_nothing(): void
    {
        $streaming = SubscriptionCategory::create(['name' => 'Streaming']);
        Subscription::create([
            'category_id' => $streaming->id, 'name' => 'ILLICO+', 'slug' => 'illico-plus',
            'max_members' => 3, 'monthly_price' => 1200, 'currency' => 'CAD',
        ]);

        $this->expectException(RuntimeException::class);
        try {
            $this->migration()->up();
        } finally {
            $this->assertSame(1, Subscription::whereRaw('LOWER(name) = ?', ['illico+'])->count());
        }
    }

    public function test_rollback_is_refused_once_a_group_draft_uses_it(): void
    {
        SubscriptionCategory::create(['name' => 'Streaming']);
        $this->migration()->up();
        $offer = Subscription::where('slug', 'illico')->sole();
        $this->migration()->down();
        $this->assertNull(Subscription::find($offer->id));

        $this->migration()->up();
        $offer = Subscription::where('slug', 'illico')->sole();
        \Illuminate\Support\Facades\DB::table('group_drafts')->insert($this->draftRow($offer->id));

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    private function draftRow(int $subscriptionId): array
    {
        return [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => \App\Models\User::factory()->create()->id,
            'data' => json_encode(['subscription_id' => $subscriptionId]),
            'version' => 1, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
