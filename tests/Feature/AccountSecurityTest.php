<?php

namespace Tests\Feature;

use App\Features\Auth\Middleware\EnsureSessionIsCurrent;
use App\Features\Auth\Services\AccountSession;
use App\Features\Auth\Services\SocialAuthService;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\OauthProvider;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuth2User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AccountSecurityTestCase;

class AccountSecurityTest extends AccountSecurityTestCase
{
    private function google(string $email, array $raw = ['email_verified' => true], string $id = 'google-security-subject'): OAuth2User
    {
        return (new OAuth2User)->setRaw($raw)->map([
            'id' => $id, 'email' => $email, 'name' => 'Synthetic Owner',
            'nickname' => null, 'avatar' => null,
        ]);
    }

    private function credentialsUrl(User $user): string
    {
        $group = Group::factory()->withCredentials()->create();
        GroupMember::factory()->for($group)->for($user)->create(['status' => 'active']);

        return '/api/groups/'.$group->id.'/credentials';
    }

    private function newRequestAuthenticationContext(): void
    {
        Auth::forgetGuards();
        // A real request starts with the web default; auth:sanctum switches it
        // for that API request only. Laravel's shared test app retains it.
        Auth::shouldUse('web');
    }

    private function resetPassword(User $user, string $password = 'New-safe-password-2026'): string
    {
        $token = Password::createToken($user);
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return $token;
    }

    public function test_google_recovery_revokes_pre_registered_password_tokens_and_reset_links(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Pre-registered Account', 'email' => 'victim-security@example.test',
            'password' => 'Pre-registrant-password', 'password_confirmation' => 'Pre-registrant-password',
        ])->assertCreated();
        $user = User::where('email', 'victim-security@example.test')->firstOrFail();
        $oldToken = $response->json('token');
        $oldPassword = $user->password;
        $oldRemember = $user->remember_token;
        $url = $this->credentialsUrl($user);
        $resetToken = Password::createToken($user);
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google($user->email));

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password);
        $this->assertNotSame($oldPassword, $user->password);
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertSame(1, $user->auth_version);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse(Password::tokenExists($user, $resetToken));
        $this->assertDatabaseCount('wallets', 0);

        $this->post('/logout')->assertRedirect('/');
        $this->newRequestAuthenticationContext();
        $this->getJson($url, ['Authorization' => 'Bearer '.$oldToken])->assertUnauthorized();
        $this->newRequestAuthenticationContext();
        $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'Pre-registrant-password',
        ])->assertUnprocessable();
        $this->assertSame(1, User::where('email', $user->email)->count());
    }

    public function test_google_recovery_keeps_the_new_session_but_rejects_a_pre_recovery_session(): void
    {
        $user = User::factory()->unverified()->create();
        Socialite::shouldReceive('driver->user')->andReturn($this->google($user->email));

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->get('/dashboard/preferences')->assertOk();
        $this->withSession([AccountSession::KEY => ['id' => $user->id, 'version' => 0]])
            ->getJson('/dashboard/preferences')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_google_link_to_verified_password_account_preserves_legitimate_credentials(): void
    {
        $user = User::factory()->create();
        $password = $user->password;
        $token = $user->createToken('legitimate-token');
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google($user->email));

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame(0, $user->fresh()->auth_version);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public static function unverifiedGoogleAssertions(): array
    {
        return [
            'missing' => [[]], 'false' => [['email_verified' => false]],
            'string true' => [['email_verified' => 'true']],
            'contradictory' => [['email_verified' => false, 'verified_email' => true]],
        ];
    }

    #[DataProvider('unverifiedGoogleAssertions')]
    public function test_google_without_proven_email_ownership_cannot_link_or_mutate_account(array $raw): void
    {
        $user = User::factory()->unverified()->create();
        $password = $user->password;
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google($user->email, $raw));

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseCount('oauth_providers', 0);
    }

    public function test_unapproved_social_provider_is_refused_by_service_as_well_as_route(): void
    {
        $this->expectException(ValidationException::class);
        app(SocialAuthService::class)->findOrCreateUser('github', $this->google('unapproved@example.test'));
    }

    public function test_unverified_google_assertion_cannot_create_a_new_account(): void
    {
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google('unverified-google@example.test', []));

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('oauth_providers', 0);
    }

    public function test_google_identity_does_not_replace_an_existing_different_provider_subject(): void
    {
        $user = User::factory()->create();
        OauthProvider::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'original-subject']);
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google($user->email));

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $user->id, 'provider_id' => 'original-subject']);
        $this->assertDatabaseCount('oauth_providers', 1);
    }

    public function test_password_reset_revokes_old_api_token_and_is_single_use_without_affecting_other_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('compromised')->plainTextToken;
        $otherToken = $other->createToken('untouched')->plainTextToken;
        $url = $this->credentialsUrl($user);
        $otherUrl = $this->credentialsUrl($other);
        $remember = $user->remember_token;

        $resetToken = $this->resetPassword($user);

        $this->assertTrue(Hash::check('New-safe-password-2026', $user->fresh()->password));
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertSame(0, $user->tokens()->count());
        $this->newRequestAuthenticationContext();
        $this->getJson($url, ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->newRequestAuthenticationContext();
        $this->getJson($otherUrl, ['Authorization' => 'Bearer '.$otherToken])->assertOk();
        $this->newRequestAuthenticationContext();
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $resetToken,
            'password' => 'Another-password-2026', 'password_confirmation' => 'Another-password-2026',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('New-safe-password-2026', $user->fresh()->password));
        $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'New-safe-password-2026',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_password_reset_invalidates_old_and_legacy_sessions_on_any_session_driver(): void
    {
        $user = User::factory()->create();
        $this->resetPassword($user);
        Route::get('/_security-session-check', fn () => response()->json(['allowed' => true]))
            ->middleware(['web', 'auth', EnsureSessionIsCurrent::class]);

        // The store here is array, not database: invalidation cannot depend on
        // deleting a row in the sessions table alone.
        $this->actingAs($user->fresh())->withSession([AccountSession::KEY => ['id' => $user->id, 'version' => 0]])
            ->getJson('/_security-session-check')->assertUnauthorized();
        $this->actingAs($user->fresh())->withSession([AccountSession::KEY => null])
            ->getJson('/_security-session-check')->assertUnauthorized();
        $this->actingAs($user->fresh())->withSession([AccountSession::KEY => ['id' => $user->id, 'version' => 1]])
            ->getJson('/_security-session-check')->assertOk();
    }

    public function test_normal_password_login_after_recovery_stamps_current_session_version(): void
    {
        $user = User::factory()->create();
        $this->resetPassword($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'New-safe-password-2026'])
            ->assertRedirect('/dashboard');
        $this->get('/dashboard/preferences')->assertOk();
        $this->assertSame(['id' => $user->id, 'version' => 1], session(AccountSession::KEY));
    }

    public function test_password_change_revokes_tokens_and_other_sessions_but_keeps_the_current_session(): void
    {
        $user = User::factory()->create();
        $user->createToken('previous-device');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');

        $this->put('/password', [
            'current_password' => 'password', 'password' => 'Changed-safe-password',
            'password_confirmation' => 'Changed-safe-password',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertTrue(Hash::check('Changed-safe-password', $user->fresh()->password));
        $this->get('/dashboard/preferences')->assertOk();
        $this->withSession([AccountSession::KEY => ['id' => $user->id, 'version' => 0]])
            ->getJson('/dashboard/preferences')->assertUnauthorized();
    }

    public function test_admin_privilege_cannot_be_obtained_by_registering_the_historical_email(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Synthetic Registration', 'email' => 'briceyouatchui@gmail.com',
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
            'is_admin' => true, 'email_verified_at' => now()->toISOString(), 'auth_version' => 99,
        ])->assertCreated()->assertJsonMissingPath('user.is_admin')->assertJsonMissingPath('user.auth_version');
        $user = User::where('email', 'briceyouatchui@gmail.com')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->actingAs($user)->get('/admin')->assertRedirect(route('verification.notice'));
        $this->getJson('/admin')->assertStatus(409);
        $user->markEmailAsVerified();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_role_and_security_version_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_admin' => true, 'auth_version' => 99])->save();

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame(0, $user->fresh()->auth_version);
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_explicit_admin_requires_verified_email_and_active_account(): void
    {
        $user = User::factory()->unverified()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->assertFalse($user->isAdmin());
        $this->actingAs($user)->get('/admin')->assertRedirect(route('verification.notice'));
        $this->getJson('/admin')->assertStatus(409);
        $user->markEmailAsVerified();
        $this->actingAs($user)->get('/admin')->assertOk();
        $user->forceFill(['status' => 'banned'])->save();
        $this->assertFalse($user->isAdmin());
        $this->actingAs($user)->getJson('/admin')->assertForbidden();
    }

    public function test_deleting_and_re_registering_a_former_admin_does_not_restore_the_role(): void
    {
        $old = User::factory()->create(['email' => 'briceyouatchui@gmail.com']);
        $old->forceFill(['is_admin' => true])->save();
        $old->delete();
        $this->postJson('/api/register', [
            'name' => 'Different Registrant', 'email' => $old->email,
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
        ])->assertCreated();
        $new = User::where('email', $old->email)->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertFalse($new->is_admin);
        $this->assertTrue(User::withTrashed()->findOrFail($old->id)->is_admin);
        $this->actingAs($new)->get('/admin')->assertRedirect(route('verification.notice'));
        $this->getJson('/admin')->assertStatus(409);
        $new->markEmailAsVerified();
        $this->actingAs($new)->get('/admin')->assertForbidden();
    }

    public static function migrationAdminCases(): array
    {
        return [
            'verified active' => [true, 'active', false, true],
            'unverified' => [false, 'active', false, false],
            'banned' => [true, 'banned', false, false],
            'deleted' => [true, 'active', true, false],
        ];
    }

    #[DataProvider('migrationAdminCases')]
    public function test_admin_migration_only_preserves_the_verified_eligible_historical_account(bool $verified, string $status, bool $deleted, bool $expected): void
    {
        $user = User::factory()->create([
            'email' => 'briceyouatchui@gmail.com', 'email_verified_at' => $verified ? now() : null,
            'status' => $status, 'deleted_at' => $deleted ? now() : null,
        ]);
        $other = User::factory()->create();
        $migration = require database_path('migrations/2026_10_06_130000_add_admin_role_to_users_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame($expected, User::withTrashed()->findOrFail($user->id)->is_admin);
        $this->assertFalse($other->fresh()->is_admin);
        $this->assertSame(0, $other->fresh()->auth_version);
        $this->assertSame(2, User::withTrashed()->count());
    }

    public static function blockedStates(): array
    {
        return ['suspended' => ['suspended', null], 'temporary' => ['suspended', '+1 day'], 'banned' => ['banned', '-1 day']];
    }

    public function test_admin_migration_round_trip_preserves_re_registered_and_deleted_accounts(): void
    {
        $old = User::factory()->create(['email' => 'retained-history@example.test']);
        $old->delete();
        $new = User::factory()->create(['email' => $old->email]);
        $snapshot = DB::table('users')->orderBy('id')->get(['id', 'email', 'password', 'deleted_at'])->toJson();
        $migration = require database_path('migrations/2026_10_06_130000_add_admin_role_to_users_table.php');

        $migration->down();
        $this->assertSame($snapshot, DB::table('users')->orderBy('id')->get(['id', 'email', 'password', 'deleted_at'])->toJson());
        $migration->up();

        $this->assertSame($snapshot, DB::table('users')->orderBy('id')->get(['id', 'email', 'password', 'deleted_at'])->toJson());
        $this->assertFalse($new->fresh()->is_admin);
        $this->assertFalse(User::withTrashed()->findOrFail($old->id)->is_admin);
        $this->postJson('/api/register', [
            'name' => 'Duplicate Active Account', 'email' => $old->email,
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(2, User::withTrashed()->count());
    }

    #[DataProvider('blockedStates')]
    public function test_suspended_and_banned_accounts_cannot_log_in_or_reuse_api_tokens(string $status, ?string $until): void
    {
        $user = User::factory()->create(['status' => $status, 'suspended_until' => $until ? now()->modify($until) : null]);
        $token = $user->createToken('issued-before-block')->plainTextToken;
        $url = $this->credentialsUrl($user);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $this->assertSame(1, $user->tokens()->count());
        $this->newRequestAuthenticationContext();
        $this->getJson($url, ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $this->newRequestAuthenticationContext();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google($user->email));
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, OauthProvider::count());
    }

    public function test_expired_temporary_suspension_allows_login_without_changing_historical_status(): void
    {
        $user = User::factory()->create(['status' => 'suspended', 'suspended_until' => now()->subMinute()]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->google($user->email));
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('suspended', $user->fresh()->status);
    }

    public function test_existing_web_session_is_blocked_when_the_account_is_suspended(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $user->forceFill(['status' => 'suspended'])->save();
        Auth::guard('web')->setUser($user);
        $this->getJson('/dashboard/preferences')->assertForbidden();
        $this->assertGuest();
    }

    public function test_email_verification_is_required_but_recovery_and_verification_remain_accessible(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->getJson('/dashboard/preferences')->assertStatus(409);
        $this->get('/verify-email')->assertOk();
        $this->post('/email/verification-notification')->assertRedirect();
        Notification::assertSentTo($user, VerifyEmail::class);

        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->get($expired)->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-invalid');
        $this->getJson($expired)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $valid = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->get($valid.'&modified=1')->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-invalid');
        $this->getJson($valid.'&modified=1')->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->get($valid)->assertRedirect('/dashboard?verified=1');
        $this->get('/dashboard/preferences')->assertOk();
    }

    public function test_api_and_web_registration_send_verification_without_creating_admin_roles(): void
    {
        $this->postJson('/api/register', [
            'name' => 'API Registration', 'email' => 'api-verification@example.test',
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
        ])->assertCreated();
        $apiUser = User::where('email', 'api-verification@example.test')->firstOrFail();
        Notification::assertSentTo($apiUser, VerifyEmail::class);
        $this->assertFalse($apiUser->is_admin);

        $this->post('/register', [
            'name' => 'Web Registration', 'email' => 'web-verification@example.test',
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
            'is_admin' => true,
        ])->assertRedirect('/dashboard');
        $webUser = User::where('email', 'web-verification@example.test')->firstOrFail();
        Notification::assertSentTo($webUser, VerifyEmail::class);
        $this->assertFalse($webUser->is_admin);
    }
}
