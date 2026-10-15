<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Countries;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupDraftTestCase;

class MarketPreferenceTest extends GroupDraftTestCase
{
    public function test_guest_choice_is_inherited_at_login_without_inferring_legal_residence(): void
    {
        $user = User::factory()->create(['country' => null, 'market_country' => null]);
        $this->post('/market', ['country' => 'BE']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertSame('BE', $user->fresh()->market_country);
        $this->assertNull($user->fresh()->country);
        $this->get('/dashboard/groups/create')->assertInertia(fn (Assert $page) => $page
            ->where('suggestedCurrency', 'EUR')->where('market.country', 'BE'));
    }

    public function test_login_never_overwrites_an_existing_account_preference_or_residence(): void
    {
        $user = User::factory()->create(['country' => 'CA', 'market_country' => 'CA']);
        $this->post('/market', ['country' => 'BE']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertSame('CA', $user->fresh()->market_country);
        $this->assertSame('CA', $user->fresh()->country);
        $this->get('/dashboard/groups/create')->assertInertia(fn (Assert $page) => $page
            ->where('suggestedCurrency', 'CAD')->where('market.country', 'CA'));
    }

    public static function markets(): array
    {
        return array_map(fn ($option) => [$option['code'], $option['currency']], Countries::options());
    }

    #[DataProvider('markets')]
    public function test_guest_country_selection_proposes_the_native_currency_and_survives_navigation(string $country, string $currency): void
    {
        config(['payments.eur_enabled' => true]);
        $this->from('/services')->post('/market', ['country' => $country])->assertRedirect('/services')
            ->assertSessionHas('market_country', $country);
        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->where('market.country', $country)->where('market.currency', $currency)
            ->where('market.enabled', true)->has('market.countries', 22));
        $this->assertDatabaseCount('users', 0);
        $this->assertNoDraftSideEffects();
    }

    public function test_unknown_countries_and_client_currency_are_not_trusted(): void
    {
        foreach (['US', 'GB', 'CH', 'ZZ', '', ['CA']] as $country) {
            $this->postJson('/market', ['country' => $country, 'currency' => 'EUR'])
                ->assertUnprocessable()->assertJsonValidationErrors('country');
        }
        $this->post('/market', ['country' => 'CA', 'currency' => 'EUR', 'user_id' => 999])->assertRedirect();
        $this->get('/services')->assertInertia(fn (Assert $page) => $page->where('market.currency', 'CAD'));
    }

    public function test_country_changes_are_rate_limited_without_applying_the_rejected_choice(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/market', ['country' => 'CA'])->assertRedirect();
        }
        $this->postJson('/market', ['country' => 'BE'])->assertStatus(429);
        $this->assertSame('CA', session('market_country'));
    }

    public function test_logged_in_owner_can_change_discovery_without_changing_stripe_or_existing_drafts(): void
    {
        $owner = $this->readyOwner(['country' => 'CA', 'market_country' => 'CA']);
        $other = User::factory()->create(['market_country' => 'CA']);
        $draft = $this->draftFor($owner);
        $before = $draft->fresh()->getAttributes();
        $this->actingAs($owner)->post('/market', [
            'country' => 'BE', 'user_id' => $other->id, 'stripe_connect_account_id' => 'acct_attacker',
        ])->assertRedirect();
        $this->assertSame('BE', $owner->fresh()->market_country);
        $this->assertSame('CA', $owner->fresh()->country);
        $this->assertSame('acct_draft_test', $owner->fresh()->stripe_connect_account_id);
        $this->assertSame('CA', $other->fresh()->market_country);
        $this->assertSame($before, $draft->fresh()->getAttributes());
        $this->withSession(['market_country' => 'CA'])->get('/dashboard/groups/create')
            ->assertInertia(fn (Assert $page) => $page->where('suggestedCurrency', 'EUR')->where('market.country', 'BE'));
        $this->get('/dashboard/groups/drafts/'.$draft->id)->assertInertia(fn (Assert $page) => $page
            ->where('draft.data.currency', 'CAD')->where('draft.data.total_price', $draft->data['total_price']));
        $this->postJson('/api/owner/country', ['country' => 'BE'])->assertStatus(405);
        $this->patchJson('/api/owner/country', ['country' => 'BE'])->assertUnprocessable();
        $this->assertNoDraftSideEffects();
    }

    public function test_discovery_filters_groups_by_native_currency_without_relabelling_catalogue_prices(): void
    {
        $service = Subscription::factory()->create(['currency' => 'CAD', 'monthly_price' => 2500, 'is_active' => true]);
        $owner = User::factory()->create();
        foreach (['CAD', 'EUR'] as $currency) {
            Group::factory()->create(['subscription_id' => $service->id, 'owner_id' => $owner->id,
                'currency' => $currency, 'status' => 'open', 'visibility' => 'public', 'current_members' => 1, 'max_members' => 4]);
        }
        $this->post('/market', ['country' => 'FR']);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('openGroups', 1)->where('openGroups.0.currency', 'EUR'));
        $this->get('/groups/service/'.$service->slug)->assertInertia(fn (Assert $page) => $page->has('groups', 1)->where('groups.0.currency', 'EUR'));
        $this->get('/services')->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => ($offer = collect($categories)->pluck('subscriptions')->flatten(1)->firstWhere('id', $service->id))
                && $offer['monthly_price'] === null && $offer['currency'] === 'EUR'));
        $this->post('/market', ['country' => 'CA']);
        $this->get('/services')->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => ($offer = collect($categories)->pluck('subscriptions')->flatten(1)->firstWhere('id', $service->id))
                && $offer['monthly_price'] === 2500 && $offer['currency'] === 'CAD'));
        $this->assertSame(2500, $service->fresh()->monthly_price);
        $this->assertSame('CAD', $service->fresh()->currency);
    }

    public function test_restricted_user_cannot_persist_a_preference(): void
    {
        $user = User::factory()->create(['status' => 'suspended', 'market_country' => 'CA']);
        $this->actingAs($user)->postJson('/market', ['country' => 'FR'])->assertForbidden();
        $this->assertSame('CA', $user->fresh()->market_country);
    }

    public function test_rollback_flag_keeps_the_selection_but_explains_unavailable_eur_engagements(): void
    {
        config(['payments.eur_enabled' => false]);
        $this->post('/market', ['country' => 'BE']);
        $this->get('/services')->assertInertia(fn (Assert $page) => $page
            ->where('market.country', 'BE')->where('market.currency', 'EUR')->where('market.enabled', false));
    }
}
