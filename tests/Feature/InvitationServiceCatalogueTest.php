<?php

namespace Tests\Feature;

use App\Features\Subscription\Services\InvitationServiceCatalogue;
use App\Models\Group;
use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use App\Models\User;
use Database\Seeders\InvitationServiceSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GroupDraftTestCase;

class InvitationServiceCatalogueTest extends GroupDraftTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These command tests exercise a pre-release empty catalogue. The release
        // migration itself is tested separately on SQLite and PostgreSQL.
        Subscription::query()->delete();
        SubscriptionCategory::query()->delete();
    }

    public function test_default_command_and_explicit_dry_run_never_write(): void
    {
        foreach ([[], ['--dry-run' => true]] as $options) {
            $this->artisan('equitab:prepare-invitation-services', $options)->assertSuccessful();
            $this->assertDatabaseCount('subscriptions', 0);
            $this->assertDatabaseCount('subscription_categories', 0);
            $this->assertNoDraftSideEffects();
        }
    }

    public function test_apply_creates_only_two_active_cad_offers_without_fictitious_prices(): void
    {
        $this->artisan('equitab:prepare-invitation-services', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('subscription_categories', 2);
        foreach ([
            ['Dropbox Family', 'dropbox-family', 'Productivité', 'dropbox.svg', null],
            ['NordPass Family', 'nordpass-family', 'Sécurité', 'nordpass.png', null],
        ] as [$name, $slug, $category, $logo, $cycle]) {
            $offer = Subscription::where('slug', $slug)->sole();
            $this->assertSame($name, $offer->name);
            $this->assertSame($category, $offer->category->name);
            $this->assertSame('/Images/services/'.$logo, $offer->logo);
            $this->assertFileExists(public_path($offer->logo));
            $this->assertSame('CAD', $offer->currency);
            $this->assertSame(6, $offer->max_members);
            $this->assertSame(5, $offer->max_members - 1);
            $this->assertSame('famille', $offer->tier);
            $this->assertSame('invitation', $offer->access_mode);
            $this->assertSame($cycle, $offer->billing_cycle);
            $this->assertNull($offer->monthly_price);
            $this->assertNull($offer->price_in_dollars);
            $this->assertTrue($offer->is_active);
            $this->assertFalse($offer->is_verified);
        }
        $this->assertNoDraftSideEffects();
    }

    public function test_rerun_preserves_ids_prices_activation_and_all_existing_group_data(): void
    {
        $legacy = Subscription::factory()->create(['monthly_price' => 2345]);
        $this->seed(InvitationServiceSeeder::class);
        $offer = Subscription::where('slug', 'nordpass-family')->sole();
        $offer->update(['monthly_price' => 1234, 'is_active' => true, 'is_verified' => true]);
        Group::factory()->create(['subscription_id' => $offer->id, 'total_price' => 3333]);
        Group::factory()->create(['subscription_id' => $legacy->id, 'total_price' => 8765]);
        $before = $this->snapshot();
        $this->travel(2)->hours();
        $this->seed(InvitationServiceSeeder::class);
        $this->artisan('equitab:prepare-invitation-services', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(23.45, $legacy->fresh()->price_in_dollars);
        $this->assertSame('credentials', $legacy->fresh()->access_mode);
    }

    public static function collisions(): array
    {
        return [
            'slug belongs to another name' => [['slug' => 'nordpass-family', 'name' => 'Other offer']],
            'name belongs to another slug' => [['slug' => 'other-offer', 'name' => 'NordPass Family']],
            'capitalized slug' => [['slug' => 'NORDPASS-FAMILY', 'name' => 'NordPass Family']],
            'whitespace name' => [['slug' => 'nordpass-family', 'name' => ' NordPass Family ']],
            'currency variant' => [['slug' => 'nordpass-family', 'name' => 'NordPass Family', 'currency' => 'EUR']],
            'legacy credential identity' => [['slug' => 'nordpass-family', 'name' => 'NordPass Family', 'access_mode' => 'credentials']],
        ];
    }

    #[DataProvider('collisions')]
    public function test_collision_aborts_the_whole_batch_without_overwriting_existing_offers(array $attributes): void
    {
        Subscription::factory()->create(['access_mode' => 'invitation', ...$attributes]);
        $before = $this->snapshot();
        $this->artisan('equitab:prepare-invitation-services', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('subscriptions', ['slug' => 'dropbox-family']);
    }

    public function test_duplicate_names_are_rejected_even_when_one_slug_is_canonical(): void
    {
        $this->seed(InvitationServiceSeeder::class);
        Subscription::factory()->create(['name' => 'Dropbox Family', 'slug' => 'other-dropbox']);
        $before = $this->snapshot();
        $this->artisan('equitab:prepare-invitation-services', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_duplicate_categories_are_reported_and_not_arbitrarily_selected(): void
    {
        SubscriptionCategory::create(['name' => 'Sécurité']);
        SubscriptionCategory::create(['name' => 'Sécurité']);
        $before = $this->snapshot();
        $this->artisan('equitab:prepare-invitation-services', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failed_insert_rolls_back_the_whole_batch_including_categories(): void
    {
        Subscription::creating(function (Subscription $offer): void {
            if ($offer->slug === 'nordpass-family') {
                throw new RuntimeException('Synthetic catalogue write failure');
            }
        });
        try {
            $this->artisan('equitab:prepare-invitation-services', ['--apply' => true])->assertFailed();
            $this->assertDatabaseCount('subscriptions', 0);
            $this->assertDatabaseCount('subscription_categories', 0);
        } finally {
            Subscription::flushEventListeners();
        }
    }

    public function test_existing_category_is_reused_without_rewriting_it(): void
    {
        $category = SubscriptionCategory::create(['name' => 'Sécurité', 'icon' => 'custom-icon', 'color' => '#123456']);
        $before = $category->fresh()->getAttributes();
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
        $this->assertSame($before, $category->fresh()->getAttributes());
        $this->assertSame(1, Subscription::where('category_id', $category->id)->count());
    }

    public function test_inactive_offers_are_not_exposed_as_available_to_owners_or_visitors(): void
    {
        $this->seed(InvitationServiceSeeder::class);
        Subscription::query()->update(['is_active' => false]);
        $owner = User::factory()->create();
        $this->actingAs($owner)->get('/dashboard/groups/create')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('subscriptions', 0));
        $this->get('/services')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => collect($categories)
                ->every(fn ($category) => count($category['subscriptions']) === 0)));
        $this->assertNoDraftSideEffects();
    }

    public function test_contradictory_command_flags_fail_without_writing(): void
    {
        $this->artisan('equitab:prepare-invitation-services', ['--apply' => true, '--dry-run' => true])->assertExitCode(2);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_categories', 0);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['subscriptions', 'subscription_categories', 'groups', 'group_members', 'payments'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
