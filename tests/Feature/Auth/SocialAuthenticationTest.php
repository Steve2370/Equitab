<?php

namespace Tests\Feature\Auth;

use App\Models\OauthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuth2User;

class SocialAuthenticationTest extends AccountSecurityTestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(
        string $id = 'google-123',
        string $email = 'jean.tremblay@example.com',
        string $name = 'Jean Tremblay',
    ): SocialiteUserContract {
        return (new OAuth2User)->setRaw(['email_verified' => true])->map([
            'id' => $id, 'email' => $email, 'name' => $name, 'nickname' => null,
            'avatar' => 'https://lh3.googleusercontent.com/fake-avatar.jpg',
        ]);
    }

    public function test_unknown_provider_is_rejected_at_the_route_level(): void
    {
        $response = $this->get('/auth/not-a-real-provider/redirect');

        $response->assertStatus(404);
    }

    public function test_redirect_route_redirects_to_provider(): void
    {
        $response = $this->get(route('auth.social.redirect', ['provider' => 'google']));

        $response->assertStatus(302);
    }

    public function test_new_user_is_created_on_first_social_login(): void
    {
        Mail::fake();

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

        $user = User::where('email', 'jean.tremblay@example.com')->first();

        $this->assertNotNull($user);
        $this->assertDatabaseHas('oauth_providers', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);

        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertNull($user->wallet, 'The retired wallet must not be created by social sign-up.');
        $this->assertDatabaseCount('wallets', 0);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_existing_password_account_is_linked_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'jean.tremblay@example.com',
            'email_verified_at' => null,
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $this->get(route('auth.social.callback', ['provider' => 'google']));

        $existing->refresh();

        $this->assertDatabaseHas('oauth_providers', [
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);
        $this->assertNotNull($existing->email_verified_at, 'Linking via a provider should mark the email verified.');
        $this->assertNull($existing->password, 'An unverified pre-registrant must lose their old password.');
        $this->assertAuthenticatedAs($existing);

        // No duplicate account should have been created.
        $this->assertSame(1, User::where('email', 'jean.tremblay@example.com')->count());
    }

    public function test_returning_social_user_is_recognized_without_duplicating(): void
    {
        $existing = User::factory()->create(['email' => 'jean.tremblay@example.com']);
        OauthProvider::create([
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertSame(1, User::count());
        $this->assertSame(1, OauthProvider::count());
        $this->assertAuthenticatedAs($existing);
    }

    public function test_suspended_user_cannot_login_via_social(): void
    {
        $existing = User::factory()->create([
            'email' => 'jean.tremblay@example.com',
            'status' => 'suspended',
        ]);
        OauthProvider::create([
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_provider_failure_redirects_to_login_with_status(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \Exception('invalid_state'));

        $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }
}
