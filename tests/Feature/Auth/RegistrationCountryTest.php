<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\Services\SocialAuthService;
use App\Models\User;
use App\Support\Countries;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use PHPUnit\Framework\Attributes\DataProvider;

class RegistrationCountryTest extends AccountSecurityTestCase
{
    public function test_google_login_inherits_discovery_but_does_not_infer_residence(): void
    {
        $identity = (new OAuthUser)->setRaw(['email_verified' => true])->map([
            'id' => 'synthetic-market-guest', 'email' => 'market-guest@example.test', 'name' => 'Synthetic member',
        ]);
        Socialite::shouldReceive('driver->user')->once()->andReturn($identity);
        $this->withSession(['market_country' => 'FR'])->get('/auth/google/callback')->assertRedirect('/dashboard');
        $user = User::where('email', 'market-guest@example.test')->sole();
        $this->assertSame('FR', $user->market_country);
        $this->assertNull($user->country);
    }

    public function test_google_round_trip_keeps_the_selected_country_in_the_session(): void
    {
        Socialite::shouldReceive('driver->redirect')->once()->andReturn(redirect('https://oauth.example.test/consent'));
        $this->get('/auth/google/redirect?country=BE')->assertRedirect('https://oauth.example.test/consent')
            ->assertSessionHas('registration_country', 'BE');
        $identity = (new OAuthUser)->setRaw(['email_verified' => true])->map([
            'id' => 'synthetic-market-roundtrip', 'email' => 'market-roundtrip@example.test', 'name' => 'Synthetic member',
        ]);
        Socialite::shouldReceive('driver->user')->once()->andReturn($identity);
        $this->get('/auth/google/callback')->assertRedirect('/dashboard')->assertSessionMissing('registration_country');
        $user = User::where('email', 'market-roundtrip@example.test')->sole();
        $this->assertSame('BE', $user->country);
        $this->assertSame('BE', $user->market_country);
    }

    public function test_google_rejects_an_unsupported_country_before_leaving_the_site(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->getJson('/auth/google/redirect?country=US')->assertUnprocessable()->assertJsonValidationErrors('country');
    }

    public function test_google_login_without_selection_clears_an_old_registration_country(): void
    {
        Socialite::shouldReceive('driver->redirect')->once()->andReturn(redirect('https://oauth.example.test/consent'));
        $this->withSession(['registration_country' => 'BE'])->get('/auth/google/redirect')
            ->assertRedirect('https://oauth.example.test/consent')->assertSessionMissing('registration_country');
    }

    public static function registrations(): array
    {
        $cases = [];
        foreach (Countries::codes() as $country) {
            foreach (['/register', '/api/register'] as $endpoint) {
                $cases[] = [$endpoint, $country];
            }
        }

        return $cases;
    }

    #[DataProvider('registrations')]
    public function test_registration_records_an_explicit_country_without_bypassing_verification(string $endpoint, string $country): void
    {
        $response = $this->postJson($endpoint, [
            'name' => 'Synthetic member', 'email' => 'market-registration@example.test',
            'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!',
            'country' => $country, 'market_country' => 'US', 'currency' => 'USD',
            'stripe_connect_account_id' => 'acct_forbidden',
        ]);
        $endpoint === '/register' ? $response->assertRedirect('/dashboard') : $response->assertCreated();
        $user = User::where('email', 'market-registration@example.test')->sole();
        $this->assertSame($country, $user->country);
        $this->assertSame($country, $user->market_country);
        $this->assertNull($user->stripe_connect_account_id);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_country_is_required_and_unsupported_countries_are_rejected(): void
    {
        foreach (['/register', '/api/register'] as $endpoint) {
            foreach ([null, 'US', 'GB', 'CH', 'ZZ', ''] as $country) {
                $this->postJson($endpoint, [
                    'name' => 'Synthetic member', 'email' => 'invalid-country@example.test',
                    'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!', 'country' => $country,
                ])->assertUnprocessable()->assertJsonValidationErrors('country');
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_creation_accepts_the_explicit_selection_but_existing_residence_is_never_overwritten(): void
    {
        $identity = (new OAuthUser)->setRaw(['email_verified' => true])->map([
            'id' => 'synthetic-market-google', 'email' => 'market-google@example.test', 'name' => 'Synthetic member',
        ]);
        $service = app(SocialAuthService::class);
        $new = $service->findOrCreateUser('google', $identity, 'BE');
        $this->assertSame('BE', $new->country);
        $this->assertSame('BE', $new->market_country);
        $same = $service->findOrCreateUser('google', $identity, 'CA');
        $this->assertSame($new->id, $same->id);
        $this->assertSame('BE', $same->country);
        $this->assertSame('BE', $same->market_country);
    }
}
