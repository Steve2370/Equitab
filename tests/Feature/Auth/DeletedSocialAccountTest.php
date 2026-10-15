<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\Services\SocialAuthService;
use App\Mail\WelcomeUser;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use PHPUnit\Framework\Attributes\DataProvider;

class DeletedSocialAccountTest extends AccountSecurityTestCase
{
    private function identity(bool $verified = true): OAuthUser
    {
        return (new OAuthUser)->setRaw(['email_verified' => $verified])->map([
            'id' => 'deleted-account-google', 'email' => 'returning@example.test',
            'name' => 'Returning member', 'avatar' => 'https://example.test/avatar.png',
        ]);
    }

    private function deletedAccount(string $status = 'active'): User
    {
        $user = User::factory()->create([
            'email' => 'returning@example.test', 'status' => $status, 'country' => 'CA',
            'market_country' => 'CA', 'is_admin' => true,
            'stripe_customer_id' => 'cus_old_account', 'stripe_connect_account_id' => 'acct_old_account',
            'identity_status' => 'verified', 'stripe_connect_status' => 'active',
        ]);
        $user->oauthProviders()->create(['provider' => 'google', 'provider_id' => 'deleted-account-google']);
        $user->delete();

        return $user;
    }

    public function test_returning_google_identity_gets_a_fresh_account_without_restoring_history(): void
    {
        $old = $this->deletedAccount();
        $group = Group::factory()->create(['owner_id' => $old->id]);
        $payment = Payment::factory()->create(['group_id' => $group->id, 'user_id' => $old->id]);
        $before = [User::withTrashed()->findOrFail($old->id)->getRawOriginal(), $group->fresh()->getRawOriginal(), $payment->fresh()->getRawOriginal()];
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->identity());

        $this->withSession(['registration_country' => 'BE'])->get('/auth/google/callback')
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors();

        $new = User::where('email', 'returning@example.test')->sole();
        $this->assertAuthenticatedAs($new);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('BE', $new->country);
        $this->assertSame('BE', $new->market_country);
        $this->assertTrue($new->hasVerifiedEmail());
        $this->assertFalse($new->is_admin);
        $this->assertNull($new->password);
        $this->assertNull($new->stripe_customer_id);
        $this->assertNull($new->stripe_connect_account_id);
        $this->assertNotSame('verified', $new->identity_status);
        $this->assertSame(0, $new->ownedGroups()->count());
        $this->assertSame(0, $new->groupMembers()->count());
        $this->assertSame(0, $new->payments()->count());
        $this->assertSame(0, $new->tokens()->count());
        $this->assertSame(2, User::withTrashed()->count());
        $this->assertEquals($before, [User::withTrashed()->findOrFail($old->id)->getRawOriginal(), $group->fresh()->getRawOriginal(), $payment->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('oauth_providers', 1);
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $new->id, 'provider_id' => 'deleted-account-google']);
        $this->assertDatabaseCount('wallets', 0);
        Mail::assertSent(WelcomeUser::class, 1);

        $same = app(SocialAuthService::class)->findOrCreateUser('google', $this->identity(), 'FR');
        $this->assertSame($new->id, $same->id);
        $this->assertSame('BE', $same->country);
        $this->assertSame(2, User::withTrashed()->count());
        Mail::assertSent(WelcomeUser::class, 1);
    }

    public function test_google_can_link_to_the_new_verified_password_account_without_replacing_it(): void
    {
        $old = $this->deletedAccount();
        $current = User::factory()->create(['email' => $old->email, 'country' => 'FR', 'market_country' => 'FR']);
        $password = $current->getRawOriginal('password');
        $user = app(SocialAuthService::class)->findOrCreateUser('google', $this->identity(), 'BE');

        $this->assertSame($current->id, $user->id);
        $this->assertSame($password, $user->getRawOriginal('password'));
        $this->assertSame('FR', $user->country);
        $this->assertFalse($user->is_admin);
        $this->assertTrue(User::withTrashed()->findOrFail($old->id)->trashed());
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $user->id, 'provider_id' => 'deleted-account-google']);
        Mail::assertNothingSent();
    }

    public function test_reclaiming_an_unverified_replacement_revokes_pre_registration_credentials(): void
    {
        $old = $this->deletedAccount();
        $current = User::factory()->unverified()->create(['email' => $old->email]);
        $current->createToken('untrusted-pre-registration');
        $current->oauthProviders()->create(['provider' => 'google', 'provider_id' => 'untrusted-identity']);
        $user = app(SocialAuthService::class)->findOrCreateUser('google', $this->identity());

        $this->assertSame($current->id, $user->id);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertGreaterThan($current->auth_version, $user->auth_version);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseCount('oauth_providers', 1);
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $user->id, 'provider_id' => 'deleted-account-google']);
    }

    public static function restrictedAccounts(): array
    {
        return [
            'suspended' => ['suspended', false], 'banned' => ['banned', false],
            'suspended with replacement' => ['suspended', true], 'banned with replacement' => ['banned', true],
        ];
    }

    #[DataProvider('restrictedAccounts')]
    public function test_deletion_does_not_bypass_a_restriction(string $status, bool $replacement): void
    {
        $old = $this->deletedAccount($status);
        if ($replacement) {
            User::factory()->create(['email' => $old->email]);
        }
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->identity());
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email')
            ->assertSessionHas('status', 'Ce compte est suspendu ou désactivé.');
        $this->assertGuest();
        $this->assertSame($replacement ? 2 : 1, User::withTrashed()->count());
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $old->id, 'provider_id' => 'deleted-account-google']);
        Mail::assertNothingSent();
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')->where('status', 'Ce compte est suspendu ou désactivé.'));
    }

    #[DataProvider('restrictedReplacementAccounts')]
    public function test_a_restricted_replacement_account_cannot_take_the_deleted_identity(string $status): void
    {
        $old = $this->deletedAccount();
        User::factory()->create(['email' => $old->email, 'status' => $status]);
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->identity());
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $old->id, 'provider_id' => 'deleted-account-google']);
        $this->assertSame(2, User::withTrashed()->count());
        Mail::assertNothingSent();
    }

    public static function restrictedReplacementAccounts(): array
    {
        return [['suspended'], ['banned']];
    }

    public function test_an_unverified_google_assertion_cannot_reassign_the_old_link(): void
    {
        $old = $this->deletedAccount();
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->identity(false));
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertDatabaseHas('oauth_providers', ['user_id' => $old->id, 'provider_id' => 'deleted-account-google']);
    }

    public function test_an_existing_different_verified_identity_is_not_overwritten(): void
    {
        $old = $this->deletedAccount();
        $current = User::factory()->create(['email' => $old->email]);
        $current->oauthProviders()->create(['provider' => 'google', 'provider_id' => 'another-verified-identity']);
        $links = DB::table('oauth_providers')->orderBy('id')->get()->toArray();
        Socialite::shouldReceive('driver->user')->once()->andReturn($this->identity());
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertEquals($links, DB::table('oauth_providers')->orderBy('id')->get()->toArray());
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_repeated_identity_conflicts_return_a_visible_message_without_partial_accounts(): void
    {
        $attempts = 0;
        User::creating(function () use (&$attempts): void {
            $attempts++;
            throw new UniqueConstraintViolationException('sqlite', 'insert into users', [],
                new \PDOException('UNIQUE constraint failed: users.email'));
        });
        try {
            Socialite::shouldReceive('driver->user')->once()->andReturn($this->identity());
            $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email')
                ->assertSessionHas('status', 'Une connexion est déjà en cours. Veuillez réessayer.');
            $this->assertSame(3, $attempts);
            $this->assertGuest();
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('oauth_providers', 0);
            Mail::assertNothingSent();
        } finally {
            User::flushEventListeners();
        }
    }

    public function test_an_unrelated_database_failure_is_not_hidden_as_an_identity_conflict(): void
    {
        $attempts = 0;
        $failure = new UniqueConstraintViolationException('sqlite', 'insert into users', [],
            new \PDOException('UNIQUE constraint failed: users.id'));
        User::creating(function () use (&$attempts, $failure): void {
            $attempts++;
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        try {
            app(SocialAuthService::class)->findOrCreateUser('google', $this->identity());
        } finally {
            User::flushEventListeners();
            $this->assertSame(1, $attempts);
            $this->assertDatabaseCount('users', 0);
        }
    }
}
