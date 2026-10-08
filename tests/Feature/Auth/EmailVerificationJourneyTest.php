<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;

class EmailVerificationJourneyTest extends AccountSecurityTestCase
{
    private function inertia(): array
    {
        return [
            'X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/dashboard')) ?? '',
        ];
    }

    private function signedUrl(User $user, bool $expired = false): string
    {
        return URL::temporarySignedRoute('verification.verify', $expired ? now()->subMinute() : now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
    }

    public static function transports(): array
    {
        return ['HTML' => [false], 'Inertia' => [true]];
    }

    #[DataProvider('transports')]
    public function test_existing_unverified_login_reaches_confirmation_then_returns_to_the_requested_page(bool $inertia): void
    {
        $member = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
        if ($inertia) {
            $this->withHeaders($this->inertia());
        }
        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard/subscriptions?tab=owned')->assertRedirect(route('verification.notice'))
            ->assertSessionHas('url.intended', '/dashboard/subscriptions?tab=owned');
        $screen = $this->get('/verify-email')->assertOk();
        if ($inertia) {
            $screen->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'Auth/VerifyEmail');
        }
        $this->assertFalse($member->fresh()->hasVerifiedEmail());
        $this->get($this->signedUrl($member))->assertRedirect('/dashboard/subscriptions?tab=owned');
        $this->assertTrue($member->fresh()->hasVerifiedEmail());
        $this->get('/dashboard/subscriptions?tab=owned')->assertOk();
    }

    public function test_registration_sends_one_confirmation_and_reaches_the_confirmation_screen_with_inertia(): void
    {
        $this->withHeaders($this->inertia())->post('/register', [
            'name' => 'Camille Démo', 'email' => 'journey@example.test',
            'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!',
        ])->assertRedirect('/dashboard');
        $user = User::where('email', 'journey@example.test')->sole();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->get('/verify-email')->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Auth/VerifyEmail');
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_registration_renders_and_delivers_the_personalized_confirmation_to_an_offline_mail_transport(): void
    {
        // Exercise the real event -> notification -> mail path, without SMTP/network.
        config(['mail.default' => 'array', 'mail.mailers.array.transport' => 'array']);
        Mail::swap(new MailManager($this->app));
        Notification::swap(new ChannelManager($this->app));
        $this->post('/register', [
            'name' => 'Camille Démo', 'email' => 'delivery@example.test',
            'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!',
        ])->assertRedirect('/dashboard');
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $messages = $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage());
        $this->assertCount(2, $messages, 'Existing welcome mail plus the confirmation, with no duplicates.');
        $confirmation = $messages->sole(fn ($mail) => $mail->getSubject() === 'Confirmez votre courriel pour commencer sur EquitAb');
        $this->assertSame('delivery@example.test', $confirmation->getTo()[0]->getAddress());
        $this->assertStringContainsString('Bonjour Camille Démo,', $confirmation->getHtmlBody());
        $this->assertStringContainsString('Confirmer mon courriel', $confirmation->getTextBody());
        $this->assertStringContainsString('verify-email/', $confirmation->getTextBody());
        $this->assertFalse(User::where('email', 'delivery@example.test')->sole()->hasVerifiedEmail());
    }

    public static function protectedJson(): array
    {
        return ['web JSON' => ['/dashboard/preferences', true], 'draft JSON contract' => ['/group-drafts', false],
            'API HTML accept' => ['/api/group-drafts', false], 'API JSON' => ['/api/group-drafts', true]];
    }

    #[DataProvider('protectedJson')]
    public function test_api_and_draft_contracts_still_deny_unverified_accounts_without_a_redirect(string $url, bool $json): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, str_starts_with($url, '/api/') ? 'sanctum' : 'web');
        $response = $json ? $this->getJson($url) : $this->get($url);
        $response->assertStatus(409)->assertHeader('Content-Type', 'application/json')
            ->assertSessionMissing('url.intended');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_blocked_mutations_do_not_become_get_return_destinations_or_create_groups(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->withHeaders($this->inertia())->post('/groups', ['name' => 'Must not exist'])
            ->assertRedirect(route('verification.notice'))->assertSessionMissing('url.intended');
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_existing_invitation_destination_is_preserved_and_external_redirect_input_is_ignored(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->withSession(['url.intended' => '/invite/synthetic/continue'])
            ->get('/dashboard?redirect=https://outside.example.test')->assertRedirect(route('verification.notice'))
            ->assertSessionHas('url.intended', '/invite/synthetic/continue');
    }

    public static function invalidLinks(): array
    {
        return ['expired HTML' => ['expired', false], 'expired Inertia' => ['expired', true],
            'tampered HTML' => ['tampered', false], 'tampered Inertia' => ['tampered', true]];
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_links_offer_recovery_without_confirming_any_email(string $kind, bool $inertia): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        if ($inertia) {
            $this->withHeaders($this->inertia());
        }
        $url = $this->signedUrl($user, $kind === 'expired').($kind === 'tampered' ? '&modified=1' : '');
        $this->get($url)->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-invalid');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->get('/verify-email')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_json_invalid_links_and_foreign_signed_links_cannot_verify_a_user(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $this->actingAs($user)->getJson($this->signedUrl($user, true))->assertForbidden();
        $this->get($this->signedUrl($other))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_resending_is_rate_limited_and_a_verified_user_receives_no_duplicate_confirmation(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->withHeaders($this->inertia());
        foreach (range(1, 6) as $attempt) {
            $this->from('/verify-email')->post('/email/verification-notification')->assertRedirect('/verify-email')
                ->assertSessionHas('status', 'verification-link-sent');
        }
        $this->post('/email/verification-notification')->assertRedirect(route('verification.notice'))
            ->assertSessionHasErrors('verification');
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->travel(61)->seconds();
        $user->markEmailAsVerified();
        $this->post('/email/verification-notification')->assertRedirect('/dashboard');
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_a_signed_link_can_be_used_after_logging_in_again_and_replays_do_not_change_verification_time(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->signedUrl($user);
        $this->get($url)->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($url);
        $this->get($url)->assertRedirect('/dashboard?verified=1');
        $at = $user->fresh()->email_verified_at;
        $this->travel(10)->seconds();
        $this->get($url)->assertRedirect('/dashboard?verified=1');
        $this->assertTrue($at->equalTo($user->fresh()->email_verified_at));
    }
}
