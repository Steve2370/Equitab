<?php

namespace Tests\Support;

use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

trait InvitationCataloguePriceChecks
{
    public static function referenceOffers(): array
    {
        return [['dropbox-family', 2649, 'monthly'], ['nordpass-family', 749, 'yearly']];
    }

    #[DataProvider('referenceOffers')]
    public function test_reference_price_fills_missing_data_without_repricing_any_contract_and_is_idempotent(string $slug, int $price, string $cycle): void
    {
        $offer = Subscription::where('slug', $slug)->sole();
        $offer->update(['monthly_price' => null, 'billing_cycle' => null, 'is_active' => false]);
        $group = Group::factory()->for($offer, 'subscription')->create(['total_price' => 1999]);
        $member = GroupMember::factory()->for($group)->create();
        Payment::factory()->for($group)->for($member->user)->create();
        GroupDraft::create(['owner_id' => $group->owner_id, 'data' => [
            'subscription_id' => $offer->id, 'total_price' => 1555, 'currency' => 'CAD',
        ], 'version' => 1, 'status' => 'draft']);
        $before = $this->priceSnapshot();
        $migration = require database_path('migrations/2026_10_08_000400_set_invitation_reference_prices.php');
        $migration->up();
        $after = $this->priceSnapshot();
        foreach ($after['subscriptions'] as $index => $row) {
            if ($row['id'] === $offer->id) {
                $this->assertSame($price, (int) $row['monthly_price']);
                $this->assertSame($cycle, $row['billing_cycle']);
                foreach (['monthly_price', 'billing_cycle', 'updated_at'] as $key) {
                    unset($before['subscriptions'][$index][$key], $after['subscriptions'][$index][$key]);
                }
            }
        }
        $this->assertSame($before, $after);
        $this->assertFalse($offer->fresh()->is_active);
        $snapshot = $this->priceSnapshot();
        $this->travel(2)->hours();
        $migration->up();
        $this->assertSame($snapshot, $this->priceSnapshot());
    }

    public static function configuredReferencePrices(): array
    {
        $cases = [];
        foreach (['dropbox-family', 'nordpass-family'] as $slug) {
            foreach ([[1234, 'monthly'], [1234, null], [0, null], [null, 'quarterly']] as [$price, $cycle]) {
                $cases[] = [$slug, $price, $cycle];
            }
        }

        return $cases;
    }

    #[DataProvider('configuredReferencePrices')]
    public function test_operator_catalogue_values_are_never_overwritten(string $slug, ?int $price, ?string $cycle): void
    {
        Subscription::where('slug', $slug)->update(['monthly_price' => $price, 'billing_cycle' => $cycle]);
        $before = $this->priceSnapshot();
        (require database_path('migrations/2026_10_08_000400_set_invitation_reference_prices.php'))->up();
        $this->assertSame($before, $this->priceSnapshot());
    }

    public static function ambiguousPriceIdentities(): array
    {
        return [
            [['currency' => 'EUR']], [['max_members' => 4]], [['access_mode' => 'credentials']],
            [['name' => 'NordPass Premium']], [['slug' => 'NORDPASS-FAMILY']],
        ];
    }

    #[DataProvider('ambiguousPriceIdentities')]
    public function test_ambiguous_offer_cannot_receive_a_cad_family_reference(array $attributes): void
    {
        Subscription::query()->update(['monthly_price' => null, 'billing_cycle' => null]);
        Subscription::where('slug', 'nordpass-family')->update(['monthly_price' => null, 'billing_cycle' => null, ...$attributes]);
        $before = $this->priceSnapshot();
        try {
            (require database_path('migrations/2026_10_08_000400_set_invitation_reference_prices.php'))->up();
            $this->fail('An ambiguous offer must not receive this price.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('identité de catalogue ambiguë', $exception->getMessage());
        }
        $this->assertSame($before, $this->priceSnapshot());
    }

    public function test_duplicate_name_cannot_receive_a_price_and_an_absent_offer_is_not_recreated(): void
    {
        $duplicate = Subscription::factory()->create(['name' => 'NordPass Family', 'slug' => 'another-nordpass']);
        $before = $this->priceSnapshot();
        $migration = require database_path('migrations/2026_10_08_000400_set_invitation_reference_prices.php');
        try {
            $migration->up();
            $this->fail('Duplicate identities must not be selected arbitrarily.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('identité de catalogue ambiguë', $exception->getMessage());
        }
        $this->assertSame($before, $this->priceSnapshot());
        $duplicate->delete();
        Subscription::where('slug', 'nordpass-family')->delete();
        $before = $this->priceSnapshot();
        $migration->up();
        $this->assertSame($before, $this->priceSnapshot());
    }

    public function test_deployed_catalogue_with_two_unknown_prices_is_upgraded_atomically(): void
    {
        Subscription::query()->update(['monthly_price' => null, 'billing_cycle' => null]);
        (require database_path('migrations/2026_10_08_000400_set_invitation_reference_prices.php'))->up();
        foreach (self::referenceOffers() as [$slug, $price, $cycle]) {
            $this->assertDatabaseHas('subscriptions', [
                'slug' => $slug, 'monthly_price' => $price, 'billing_cycle' => $cycle, 'currency' => 'CAD', 'max_members' => 6,
            ]);
        }
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('subscription_attempts', 0);
    }

    public function test_price_rollback_does_not_erase_later_operator_edits(): void
    {
        Subscription::where('slug', 'nordpass-family')->update(['monthly_price' => 999]);
        $before = $this->priceSnapshot();
        try {
            (require database_path('migrations/2026_10_08_000400_set_invitation_reference_prices.php'))->down();
            $this->fail('Rollback cannot reconstruct the historical reference.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Retour arrière tarif refusé', $exception->getMessage());
        }
        $this->assertSame($before, $this->priceSnapshot());
    }

    private function priceSnapshot(): array
    {
        $result = [];
        foreach (['subscriptions', 'subscription_categories', 'groups', 'group_drafts', 'group_members', 'payments', 'subscription_attempts'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
