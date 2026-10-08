<?php

namespace Tests\Feature;

use App\Features\Group\Services\OwnerPublicationEligibility;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Services\OwnerConnectAccountService;
use App\Features\Payment\Services\OwnerCountries;
use App\Features\Payment\Services\OwnerCountrySettings;
use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BillingTestCase;

class EurozoneOwnerTest extends BillingTestCase
{
    /** Independent expected list: EU euro area on 2026-10-07, not all of Europe. */
    private const EUROZONE = ['AT', 'BE', 'BG', 'HR', 'CY', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PT', 'SK', 'SI', 'ES'];

    private MockInterface $stripe;

    protected function setUp(): void
    {
        // BillingTestCase boots without .env, refuses any DB except SQLite
        // :memory:, blocks Laravel/Stripe HTTP, and migrates without an outer
        // transaction. That last condition exercises durable Connect attempts.
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        config([
            'payments.eurozone_connect_enabled' => false,
            'payments.eur_enabled' => false,
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.lock_table' => 'cache_locks',
        ]);
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(12, 0));
        $this->stripe = Mockery::mock(OwnerStripeGatewayInterface::class);
        foreach (get_class_methods(OwnerStripeGatewayInterface::class) as $method) {
            $this->stripe->shouldReceive($method)->never()->byDefault();
        }
        $this->instance(OwnerStripeGatewayInterface::class, $this->stripe);
    }

    public static function countries(): array
    {
        return array_combine(['CA', ...self::EUROZONE], array_map(fn ($code) => [$code], ['CA', ...self::EUROZONE]));
    }

    public static function eurozoneCountries(): array
    {
        return array_combine(self::EUROZONE, array_map(fn ($code) => [$code], self::EUROZONE));
    }

    public static function unsupportedCountries(): array
    {
        return ['United Kingdom' => ['GB'], 'Switzerland' => ['CH'], 'Norway' => ['NO'], 'Romania' => ['RO']];
    }

    public static function invalidCountryPayloads(): array
    {
        return [
            'omitted' => [[]], 'null' => [['country' => null]], 'empty' => [['country' => '']],
            'name instead of code' => [['country' => 'Belgique']], 'array' => [['country' => ['BE']]],
            'number' => [['country' => 42]], 'not ISO' => [['country' => 'EU']],
        ];
    }

    public function test_options_contain_exactly_canada_and_twenty_one_eurozone_countries_with_separate_gate(): void
    {
        $countries = app(OwnerCountries::class);
        $options = $countries->options();
        $this->assertCount(22, $options);
        $this->assertEqualsCanonicalizing(['CA', ...self::EUROZONE], array_column($options, 'code'));
        foreach ($options as $option) {
            $this->assertEqualsCanonicalizing(['code', 'name', 'enabled'], array_keys($option));
            $this->assertIsString($option['name']);
            $this->assertNotSame('', trim($option['name']));
            $this->assertSame($option['code'] === 'CA', $option['enabled']);
        }

        // Enabling EUR billing alone must never silently open Connect countries.
        config(['payments.eur_enabled' => true]);
        $this->assertSame($options, $countries->options());
        config(['payments.eurozone_connect_enabled' => true, 'payments.eur_enabled' => false]);
        $this->assertCount(22, array_filter($countries->options(), fn ($option) => $option['enabled']));
    }

    #[DataProvider('countries')]
    public function test_supported_country_can_be_selected_before_rollout_without_external_effects(string $country): void
    {
        $owner = User::factory()->create();
        $this->assertNull($owner->country, 'Fixtures must not silently assume Canada.');
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => $country])
            ->assertOk()->assertJsonPath('country', $country)->assertJsonPath('locked', false)
            ->assertJsonPath('canStart', $country === 'CA')->assertJsonCount(22, 'countries');
        $this->assertSame($country, $owner->fresh()->country);
        $this->assertNoFinancialEffects();
    }

    #[DataProvider('unsupportedCountries')]
    public function test_european_but_non_eurozone_countries_are_rejected_by_api_and_profile(string $country): void
    {
        config(['payments.eurozone_connect_enabled' => true]);
        $owner = User::factory()->create(['country' => 'CA']);
        $before = $owner->fresh()->getRawOriginal();
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => $country])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->patchJson('/dashboard/profile', ['name' => 'Should not be saved', 'country' => $country])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertSame($before, $owner->fresh()->getRawOriginal());
        $this->assertNoFinancialEffects();
    }

    #[DataProvider('invalidCountryPayloads')]
    public function test_invalid_country_input_is_rejected_without_mutation(array $payload): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->patchJson('/api/owner/country', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertNull($owner->fresh()->country);
        $this->assertNoFinancialEffects();
    }

    public function test_lowercase_iso_input_is_persisted_as_the_canonical_uppercase_country(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'be'])
            ->assertOk()->assertJsonPath('country', 'BE')->assertJsonPath('canStart', false);
        $this->assertSame('BE', $owner->fresh()->country);
        $this->assertSame('BE', app(OwnerCountries::class)->normalize('be'));
        $this->assertNoFinancialEffects();
    }

    public function test_country_endpoint_throttles_repeated_updates_without_applying_the_rejected_change(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        // Its own counter must not collide with the broader API counter.
        for ($request = 0; $request < 20; $request++) {
            $this->patchJson('/api/owner/country', ['country' => 'CA'])->assertOk();
        }
        $this->patchJson('/api/owner/country', ['country' => 'BE'])->assertTooManyRequests();
        $this->assertSame('CA', $owner->fresh()->country);
        $this->travel(61)->seconds();
        $this->patchJson('/api/owner/country', ['country' => 'BE'])->assertOk();
        $this->assertSame('BE', $owner->fresh()->country);
        $this->assertNoFinancialEffects();
    }

    #[DataProvider('unsupportedCountries')]
    public function test_unsupported_country_already_stored_cannot_create_a_connect_account(string $country): void
    {
        config(['payments.eurozone_connect_enabled' => true]);
        $owner = User::factory()->create(['country' => $country]);
        $this->actingAs($owner)->postJson('/api/stripe/onboarding')
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertFalse(app(OwnerCountrySettings::class)->state($owner)['canStart']);
        $this->assertNoFinancialEffects();
    }

    public function test_missing_country_is_not_guessed_from_preferences_address_or_request(): void
    {
        config(['payments.eurozone_connect_enabled' => true, 'payments.eur_enabled' => true]);
        $owner = User::factory()->create([
            'currency' => 'EUR', 'locale' => 'fr', 'timezone' => 'Europe/Brussels',
            'phone' => '+32470000000', 'address' => '1 rue Exemple', 'city' => 'Bruxelles',
            'postal_code' => '1000',
        ]);
        $this->actingAs($owner)->withHeaders(['Accept-Language' => 'fr-BE', 'CF-IPCountry' => 'BE'])
            ->postJson('/api/stripe/onboarding', ['country' => 'BE'])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertNull($owner->fresh()->country);
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertFalse(app(OwnerCountrySettings::class)->state($owner)['canStart']);
        $this->assertNoFinancialEffects();
    }

    #[DataProvider('eurozoneCountries')]
    public function test_gate_off_rejects_each_new_eurozone_account_before_attempt_is_reserved(string $country): void
    {
        config(['payments.eur_enabled' => true]);
        $owner = User::factory()->create(['country' => $country]);
        $this->actingAs($owner)->postJson('/api/stripe/onboarding')
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertNoFinancialEffects();
    }

    #[DataProvider('countries')]
    public function test_each_enabled_country_is_sent_to_stripe_without_changing_account_policy(string $country): void
    {
        // Canada must work with rollout OFF. All 21 euro countries work with it ON.
        config(['payments.eurozone_connect_enabled' => $country !== 'CA']);
        $owner = User::factory()->create(['country' => $country, 'email' => 'owner@example.test']);
        $this->stripe->shouldReceive('createAccount')->once()->andReturnUsing(function (array $parameters, string $key) use ($country, $owner): string {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame([
                'type' => 'express', 'country' => $country, 'email' => 'owner@example.test',
                'business_type' => 'individual',
                'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]],
            ], $parameters);
            $this->assertTrue(Str::isUuid($key));
            $attempt = DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole();
            $this->assertSame($key, $attempt->idempotency_key);
            $this->assertSame($parameters, json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('owner@example.test', $attempt->parameters);

            return 'acct_country_'.$country;
        });
        $this->expectLink('acct_country_'.$country);

        $this->actingAs($owner)->postJson('/api/stripe/onboarding', ['country' => 'US'])
            ->assertOk()->assertExactJson(['url' => 'https://connect.stripe.com/setup/offline']);
        $this->assertSame('acct_country_'.$country, $owner->fresh()->stripe_connect_account_id);
        $this->assertSame($country, $owner->fresh()->country);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
        $this->assertSame('not_started', $owner->fresh()->stripe_connect_status);
        $this->assertNotSame('verified', $owner->fresh()->identity_status);
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_country_change_clears_previous_address_but_never_converts_preferences_groups_or_drafts(): void
    {
        $owner = User::factory()->create([
            'country' => 'CA', 'address' => '1 rue Exemple', 'city' => 'Montréal',
            'province' => 'QC', 'postal_code' => 'H2X 1Y4', 'currency' => 'CAD', 'locale' => 'fr',
        ]);
        $group = Group::factory()->create(['owner_id' => $owner->id, 'currency' => 'CAD']);
        $draft = new GroupDraft;
        $draft->forceFill(['owner_id' => $owner->id, 'data' => ['name' => 'Mon brouillon', 'currency' => 'EUR', 'total_price' => 1703]])->save();
        $groupBefore = $group->fresh()->getRawOriginal();
        $draftBefore = $draft->fresh()->getRawOriginal();

        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'BE'])->assertOk();
        $fresh = $owner->fresh();
        foreach (['address', 'city', 'province', 'postal_code'] as $field) {
            $this->assertNull($fresh->{$field}, $field.' must not retain the previous country address.');
        }
        $this->assertSame('CAD', $fresh->currency);
        $this->assertSame('fr', $fresh->locale);
        $this->assertSame($groupBefore, $group->fresh()->getRawOriginal());
        $this->assertSame($draftBefore, $draft->fresh()->getRawOriginal());
        $this->assertDatabaseCount('owner_connect_attempts', 0);
    }

    public function test_reselecting_same_country_preserves_address(): void
    {
        $owner = User::factory()->create(['country' => 'BE', 'address' => '1 rue Exemple', 'city' => 'Bruxelles', 'province' => 'Bruxelles-Capitale', 'postal_code' => '1000']);
        $before = $owner->fresh()->getRawOriginal();
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'BE'])->assertOk();
        $this->assertSame($before, $owner->fresh()->getRawOriginal());
    }

    public static function lockedOwners(): array
    {
        return ['existing account' => ['account', 'BE'], 'legacy account unknown country' => ['account', null], 'durable attempt' => ['attempt', 'BE'], 'legacy attempt unknown country' => ['attempt', null]];
    }

    #[DataProvider('lockedOwners')]
    public function test_api_and_profile_cannot_change_country_after_account_or_attempt_exists(string $reason, ?string $country): void
    {
        $owner = User::factory()->create(['country' => $country, 'address' => '1 rue Exemple']);
        if ($reason === 'account') {
            $owner->update(['stripe_connect_account_id' => 'acct_locked']);
        } else {
            $this->persistAttempt($owner, 'BE');
        }
        $before = $owner->fresh()->getRawOriginal();
        $attemptBefore = DB::table('owner_connect_attempts')->get()->toArray();
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'CA'])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->patchJson('/dashboard/profile', ['name' => 'Bypass', 'country' => 'CA', 'address' => 'Other address'])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertSame($before, $owner->fresh()->getRawOriginal());
        $this->assertEquals($attemptBefore, DB::table('owner_connect_attempts')->get()->toArray());
        $state = app(OwnerCountrySettings::class)->state($owner->fresh());
        $this->assertTrue($state['locked']);
        $this->assertTrue($state['canStart']);
        $this->assertSame($country, $state['country']);
    }

    public function test_stale_user_cannot_change_country_after_another_request_has_persisted_an_attempt(): void
    {
        $owner = User::factory()->create(['country' => 'BE']);
        $stale = $owner->fresh();
        $attempt = $this->persistAttempt($owner, 'BE');
        try {
            app(OwnerCountrySettings::class)->select($stale, 'CA');
            $this->fail('A stale model must not bypass the durable country lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('country', $exception->errors());
        }
        $this->assertSame('BE', $owner->fresh()->country);
        $this->assertEquals($attempt, DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole());
    }

    public function test_lost_response_retry_keeps_country_parameters_and_key_when_rollout_is_disabled(): void
    {
        config(['payments.eurozone_connect_enabled' => true]);
        $owner = User::factory()->create(['country' => 'BE', 'email' => 'before@example.test']);
        $parameters = null;
        $key = null;
        $this->stripe->shouldReceive('createAccount')->once()->ordered()->withArgs(function (array $params, string $idempotency) use (&$parameters, &$key): bool {
            $this->assertSame(0, DB::transactionLevel());
            $parameters = $params;
            $key = $idempotency;

            return true;
        })->andThrow(new RuntimeException('Private synthetic SDK detail'));
        $this->actingAs($owner)->postJson('/api/stripe/onboarding')->assertStatus(503)->assertDontSee('Private synthetic SDK detail');
        $attemptBefore = DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole();
        $this->assertSame('BE', $parameters['country']);
        $this->assertNull($owner->fresh()->stripe_connect_account_id);

        config(['payments.eurozone_connect_enabled' => false]);
        $this->patchJson('/api/owner/country', ['country' => 'CA'])->assertUnprocessable()->assertJsonValidationErrors('country');
        $owner->update(['email' => 'after@example.test', 'address' => 'Updated after attempt']);
        $this->stripe->shouldReceive('createAccount')->once()->ordered()->with($parameters, $key)->andReturn('acct_retry');
        $this->expectLink('acct_retry');
        $this->postJson('/api/stripe/onboarding')->assertOk();
        $this->assertEquals($attemptBefore, DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole());
        $this->assertSame('acct_retry', $owner->fresh()->stripe_connect_account_id);
        $this->assertSame('BE', $owner->fresh()->country);
        $this->assertDatabaseCount('owner_connect_attempts', 1);
    }

    public function test_legacy_unknown_country_pending_attempt_is_replayed_without_inference_or_rewrite(): void
    {
        $owner = User::factory()->create();
        $attempt = $this->persistAttempt($owner, 'CA');
        $parameters = json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR);
        $this->stripe->shouldReceive('createAccount')->once()->with($parameters, $attempt->idempotency_key)->andReturn('acct_legacy_retry');
        $this->expectLink('acct_legacy_retry');

        $this->actingAs($owner)->postJson('/api/stripe/onboarding')->assertOk();
        $this->assertNull($owner->fresh()->country);
        $this->assertEquals($attempt, DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole());
    }

    public function test_expired_attempt_remains_locked_and_is_not_recreated_after_flag_rollback(): void
    {
        $owner = User::factory()->create(['country' => 'BE']);
        $this->persistAttempt($owner, 'BE');
        DB::table('owner_connect_attempts')->where('user_id', $owner->id)->update(['started_at' => now()->subHours(23)]);
        $before = DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole();
        $this->actingAs($owner)->postJson('/api/stripe/onboarding')->assertStatus(503);
        $this->patchJson('/api/owner/country', ['country' => 'CA'])->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertEquals($before, DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole());
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
    }

    public static function existingAccountCountries(): array
    {
        return ['Canada' => ['CA'], 'Belgium with rollout off' => ['BE'], 'unknown historical country' => [null]];
    }

    #[DataProvider('existingAccountCountries')]
    public function test_existing_account_is_reused_without_country_backfill_or_downgrading_readiness(?string $country): void
    {
        $owner = User::factory()->create([
            'country' => $country, 'stripe_connect_account_id' => 'acct_existing',
            'stripe_connect_status' => 'active', 'identity_status' => 'verified',
        ]);
        $this->expectLink('acct_existing');
        $this->actingAs($owner)->postJson('/api/stripe/onboarding')->assertOk();
        $this->assertSame($country, $owner->fresh()->country);
        $this->assertDatabaseCount('owner_connect_attempts', 0);
        $state = app(OwnerPublicationEligibility::class)->state($owner->fresh());
        $this->assertTrue($state['ready']);
        $this->assertTrue($state['country']['locked']);
        $this->assertTrue($state['country']['canStart']);
    }

    public function test_authentication_and_verification_are_required_for_country_selection(): void
    {
        $this->patchJson('/api/owner/country', ['country' => 'BE'])->assertUnauthorized();
        $owner = User::factory()->unverified()->create();
        // Keep the existing verified middleware contract (409 JSON), not a
        // redirect or the old incompatible Inertia 403 JSON response.
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'BE'])->assertStatus(409);
        $this->assertNull($owner->fresh()->country);
        $this->assertNoFinancialEffects();
    }

    public static function forbiddenAccounts(): array
    {
        return [
            'unverified' => [['email_verified_at' => null]],
            'suspended' => [['status' => 'suspended']],
            'banned' => [['status' => 'banned']],
            'temporarily suspended' => [['status' => 'suspended', 'suspended_until' => '2026-10-08 12:00:00']],
        ];
    }

    #[DataProvider('forbiddenAccounts')]
    public function test_blocked_accounts_cannot_start_connect_or_bypass_country_through_profile(array $attributes): void
    {
        config(['payments.eurozone_connect_enabled' => true]);
        $owner = User::factory()->create(['country' => 'BE', ...$attributes]);
        $this->actingAs($owner)->postJson('/api/stripe/onboarding')->assertForbidden();
        $this->actingAs($owner)->patchJson('/dashboard/profile', ['name' => 'Bypass', 'country' => 'CA'])
            ->assertStatus(array_key_exists('email_verified_at', $attributes) ? 409 : 403);
        $this->assertSame('BE', $owner->fresh()->country);
        $this->assertNoFinancialEffects();
    }

    public function test_suspended_owner_cannot_select_country(): void
    {
        $owner = User::factory()->create(['status' => 'suspended']);
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'BE'])->assertForbidden();
        $this->assertNull($owner->fresh()->country);
        $this->assertNoFinancialEffects();
    }

    public function test_foreign_user_id_and_privileged_payload_fields_are_ignored(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create(['country' => 'CA']);
        $foreignBefore = $foreign->fresh()->getRawOriginal();
        $ownerCurrency = $owner->fresh()->currency;
        $this->actingAs($owner)->patchJson('/api/owner/country', [
            'country' => 'BE', 'user_id' => $foreign->id,
            'stripe_connect_account_id' => 'acct_forged', 'stripe_connect_status' => 'active',
            'identity_status' => 'verified', 'email_verified_at' => now()->toDateTimeString(), 'currency' => 'EUR',
        ])->assertOk()->assertJsonPath('country', 'BE');
        $this->assertSame($foreignBefore, $foreign->fresh()->getRawOriginal());
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertSame('not_started', $owner->fresh()->stripe_connect_status);
        $this->assertNotSame('verified', $owner->fresh()->identity_status);
        $this->assertSame($ownerCurrency, $owner->fresh()->currency);
        $this->assertNoFinancialEffects();
    }

    public function test_profile_still_accepts_legacy_payload_without_selecting_a_country(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->patch('/dashboard/profile', [
            'name' => 'Legacy owner', 'address' => '1 rue Exemple', 'city' => 'Montréal', 'province' => 'QC', 'postal_code' => 'H2X 1Y4',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($owner->fresh()->country);
        $this->assertSame('QC', $owner->fresh()->province);
        $this->assertNoFinancialEffects();
    }

    public function test_existing_account_can_update_profile_address_without_changing_its_locked_country(): void
    {
        $owner = User::factory()->create(['country' => 'BE', 'stripe_connect_account_id' => 'acct_existing']);
        $this->actingAs($owner)->patch('/dashboard/profile', [
            'name' => 'Updated owner', 'country' => 'BE', 'address' => '2 rue Exemple',
            'city' => 'Bruxelles', 'province' => 'Bruxelles-Capitale', 'postal_code' => '1000',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $owner->fresh();
        $this->assertSame('BE', $fresh->country);
        $this->assertSame('acct_existing', $fresh->stripe_connect_account_id);
        $this->assertSame('2 rue Exemple', $fresh->address);
        $this->assertSame('Bruxelles-Capitale', $fresh->province);
        $this->assertSame('1000', $fresh->postal_code);
        $this->assertDatabaseCount('owner_connect_attempts', 0);
    }

    public function test_profile_accepts_european_region_and_postal_lengths_and_rejects_overflow(): void
    {
        $owner = User::factory()->create(['country' => 'BE']);
        $this->actingAs($owner)->patch('/dashboard/profile', [
            'name' => $owner->name, 'country' => 'BE', 'province' => str_repeat('r', 100), 'postal_code' => str_repeat('1', 20),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('r', 100), $owner->fresh()->province);
        $this->assertSame(str_repeat('1', 20), $owner->fresh()->postal_code);
        $before = $owner->fresh()->getRawOriginal();
        $this->patchJson('/dashboard/profile', [
            'name' => 'Must not change', 'country' => 'BE', 'province' => str_repeat('r', 101), 'postal_code' => str_repeat('1', 21),
        ])->assertUnprocessable()->assertJsonValidationErrors(['province', 'postal_code']);
        $this->assertSame($before, $owner->fresh()->getRawOriginal());
    }

    public function test_profile_country_change_cannot_reuse_an_old_address_from_the_same_payload(): void
    {
        $owner = User::factory()->create(['country' => 'CA', 'address' => '1 rue Exemple', 'city' => 'Montréal', 'province' => 'QC', 'postal_code' => 'H2X 1Y4']);
        $this->actingAs($owner)->patch('/dashboard/profile', [
            'name' => $owner->name, 'country' => 'BE', 'address' => $owner->address, 'city' => $owner->city,
            'province' => $owner->province, 'postal_code' => $owner->postal_code,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('BE', $owner->fresh()->country);
        foreach (['address', 'city', 'province', 'postal_code'] as $field) {
            $this->assertNull($owner->fresh()->{$field});
        }
    }

    public function test_stale_profile_tab_cannot_restore_an_address_from_the_previous_country(): void
    {
        $owner = User::factory()->create(['country' => 'CA', 'address' => '1 rue Ancienne', 'city' => 'Montréal']);
        $oldForm = ['name' => 'Must not change', 'address' => $owner->address, 'city' => $owner->city,
            'province' => 'QC', 'postal_code' => 'H2X 1Y4'];
        $this->actingAs($owner)->patchJson('/api/owner/country', ['country' => 'BE'])->assertOk();
        $afterChange = $owner->fresh()->getRawOriginal();
        foreach ([[], ['expected_country' => null], ['expected_country' => 'CA']] as $snapshot) {
            $this->patchJson('/dashboard/profile', [...$oldForm, ...$snapshot])
                ->assertUnprocessable()->assertJsonValidationErrors('country');
            $this->assertSame($afterChange, $owner->fresh()->getRawOriginal());
        }
        $this->patch('/dashboard/profile', ['name' => $owner->name, 'expected_country' => 'BE',
            'address' => '2 rue Belge', 'city' => 'Bruxelles', 'province' => null, 'postal_code' => '1000'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2 rue Belge', $owner->fresh()->address);
        $this->assertSame('BE', $owner->fresh()->country);
    }

    public function test_profile_and_owner_page_expose_the_same_country_state_without_inference(): void
    {
        $owner = User::factory()->create(['currency' => 'EUR', 'locale' => 'fr']);
        $this->actingAs($owner)->get('/dashboard/profile')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/Profile')->where('ownerCountry.country', null)
            ->where('ownerCountry.locked', false)->where('ownerCountry.canStart', false)
            ->has('ownerCountry.countries', 22));
        $this->get('/dashboard/groups/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ownerReadiness.country.country', null)->where('ownerReadiness.country.canStart', false)
            ->has('ownerReadiness.country.countries', 22));
    }

    public static function completeAddresses(): array
    {
        return [
            'Canada validated province' => ['CA', 'Montréal', 'qc', 'h2x 1y4', 'QC', 'H2X 1Y4'],
            'Belgium without region' => ['BE', 'Bruxelles', null, '1000', null, '1000'],
            'Belgium without optional postal' => ['BE', 'Bruxelles', null, null, null, null],
            'France with region' => ['FR', 'Paris', 'Île-de-France', '75001', 'Île-de-France', '75001'],
            'Ireland alphanumeric postal' => ['IE', 'Dublin', null, 'D02 X285', null, 'D02 X285'],
            'Netherlands spaced postal' => ['NL', 'Amsterdam', 'Noord-Holland', '1012 AB', 'Noord-Holland', '1012 AB'],
        ];
    }

    #[DataProvider('completeAddresses')]
    public function test_confirmed_country_is_used_in_address_without_imposing_canadian_format_on_europe(
        string $country, string $city, ?string $region, ?string $postal, ?string $expectedRegion, ?string $expectedPostal,
    ): void {
        config(['payments.eurozone_connect_enabled' => true]);
        $owner = User::factory()->create([
            'country' => $country, 'address' => '  1 rue Exemple  ', 'city' => $city,
            'province' => $region, 'postal_code' => $postal,
        ]);
        $this->stripe->shouldReceive('createAccount')->once()->andReturnUsing(function (array $parameters) use ($country, $city, $expectedRegion, $expectedPostal): string {
            $this->assertSame(0, DB::transactionLevel());
            $expected = ['line1' => '1 rue Exemple', 'city' => $city, 'country' => $country];
            if ($expectedPostal !== null) {
                $expected['postal_code'] = $expectedPostal;
            }
            if ($expectedRegion !== null) {
                $expected['state'] = $expectedRegion;
            }
            $this->assertEquals($expected, $parameters['individual']['address']);
            $this->assertArrayNotHasKey('first_name', $parameters['individual']);
            $this->assertArrayNotHasKey('last_name', $parameters['individual']);

            return 'acct_address';
        });
        $this->assertSame('acct_address', app(OwnerConnectAccountService::class)->accountId($owner));
    }

    public static function incompleteAddresses(): array
    {
        return [
            'Canada invalid province' => ['CA', 'Montréal', 'XX', 'H2X 1Y4'],
            'Canada invalid postal' => ['CA', 'Montréal', 'QC', '1000'],
            'Canada missing province' => ['CA', 'Montréal', null, 'H2X 1Y4'],
            'Europe missing city' => ['BE', null, null, '1000'],
        ];
    }

    #[DataProvider('incompleteAddresses')]
    public function test_incomplete_address_is_left_to_stripe_instead_of_fabricated(string $country, ?string $city, ?string $region, ?string $postal): void
    {
        config(['payments.eurozone_connect_enabled' => true]);
        $owner = User::factory()->create(['country' => $country, 'address' => '1 rue Exemple', 'city' => $city, 'province' => $region, 'postal_code' => $postal]);
        $this->stripe->shouldReceive('createAccount')->once()->andReturnUsing(function (array $parameters) use ($country): string {
            $this->assertSame($country, $parameters['country']);
            $this->assertArrayNotHasKey('individual', $parameters);

            return 'acct_partial';
        });
        $this->assertSame('acct_partial', app(OwnerConnectAccountService::class)->accountId($owner));
    }

    private function expectLink(string $account): void
    {
        $this->stripe->shouldReceive('createAccountLink')->once()->with(
            $account, route('stripe.onboarding.return'), route('stripe.onboarding.refresh'),
        )->andReturn('https://connect.stripe.com/setup/offline');
    }

    private function persistAttempt(User $owner, string $country): object
    {
        DB::table('owner_connect_attempts')->insert([
            'user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid(),
            'parameters' => Crypt::encryptString(json_encode([
                'type' => 'express', 'country' => $country, 'email' => $owner->email,
                'business_type' => 'individual',
                'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]],
            ], JSON_THROW_ON_ERROR)), 'started_at' => now(),
        ]);

        return DB::table('owner_connect_attempts')->where('user_id', $owner->id)->sole();
    }

    private function assertNoFinancialEffects(): void
    {
        foreach (['owner_connect_attempts', 'groups', 'payments', 'stripe_prices', 'group_members'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
