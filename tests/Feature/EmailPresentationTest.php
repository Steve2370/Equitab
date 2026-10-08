<?php

namespace Tests\Feature;

use App\Mail\AdminMessage;
use App\Mail\AutoRefundProcessed;
use App\Mail\ConnectAccountActivated;
use App\Mail\IdentityVerified;
use App\Mail\NewMemberJoined;
use App\Mail\NewMessage;
use App\Mail\PaymentConfirmed;
use App\Mail\PaymentFailed;
use App\Mail\PriceChanged;
use App\Mail\RenewalReminder;
use App\Mail\WelcomeUser;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingTestCase;

class EmailPresentationTest extends BillingTestCase
{
    private const UNSAFE_TEXT = '<script>alert("mail")</script> & Camille';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://equitab.example']);
        URL::forceRootUrl('https://equitab.example');
        URL::forceScheme('https');
    }

    public static function mailTemplates(): array
    {
        return array_combine(
            $names = ['welcome', 'payment-confirmed', 'payment-failed', 'new-member', 'renewal', 'price-decrease', 'price-increase', 'refund', 'message', 'identity', 'connect', 'admin'],
            array_map(fn ($name) => [$name], $names),
        );
    }

    #[DataProvider('mailTemplates')]
    public function test_mailables_render_with_the_shared_theme_and_escaped_data(string $name): void
    {
        $mail = $this->example($name, self::UNSAFE_TEXT);
        $html = $mail->render();

        $this->assertTheme($html);
        $this->assertStringContainsString(e(self::UNSAFE_TEXT), $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('{!!', $html);
        $this->assertStringNotContainsString('{{', $html);
        foreach ($mail->content()->with as $key => $value) {
            if (str_ends_with($key, 'Url')) {
                $this->assertStringContainsString('href="'.e($value).'"', $html);
            }
        }
        Mail::assertNothingSent();
        $this->savePreview($name, $this->example($name, 'Camille')->render());
    }

    public function test_price_variants_keep_their_distinct_explanation_and_amounts(): void
    {
        $decrease = $this->example('price-decrease', 'Camille')->render();
        $increase = $this->example('price-increase', 'Camille')->render();
        $this->assertStringContainsString('Votre part diminue.', $decrease);
        $this->assertStringContainsString('De nouveaux membres', $decrease);
        $this->assertStringContainsString('Votre part a changé.', $increase);
        $this->assertStringContainsString('Un membre a quitté', $increase);
        foreach ([$increase, $decrease] as $html) {
            $this->assertStringContainsString("6,00\u{00A0}$ CAD", $html);
            $this->assertStringContainsString("9,00\u{00A0}$ CAD", $html);
        }
    }

    public function test_admin_message_is_escaped_before_adding_line_breaks(): void
    {
        $html = (new AdminMessage(new User(['name' => 'Camille']), 'Information', "Première ligne\n<script>alert(1)</script>"))->render();
        $this->assertStringContainsString('Première ligne<br', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_verification_notification_preserves_the_signed_link(): void
    {
        $user = new User(['name' => 'Camille', 'email' => 'camille@example.test']);
        $user->id = 123;
        $message = (new VerifyEmail)->toMail($user);
        $html = (string) $message->render();
        $this->assertTheme($html);
        $this->assertSame('Confirmez votre courriel pour commencer sur EquitAb', $message->subject);
        $this->assertStringContainsString('Bonjour Camille,', $html);
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertStringContainsString('Confirmer mon courriel', $html);
        $this->assertStringNotContainsString('Hello!', $html);
        $this->assertStringContainsString('href="'.e($message->actionUrl).'"', $html);
        $this->assertTrue(URL::hasValidSignature(Request::create($message->actionUrl)));
        $this->assertFalse(URL::hasValidSignature(Request::create($message->actionUrl.'&tampered=1')));
        $this->assertStringContainsString('verify-email/123/', $message->actionUrl);
        $this->savePreview('verify-email', $html);
    }

    public function test_confirmation_name_cannot_inject_html_or_markdown_links_and_expiry_matches_the_signature(): void
    {
        $this->freezeTime();
        config(['auth.verification.expire' => 30]);
        $name = '<script>alert(1)</script> [Cliquez](https://outside.example.test) & Camille';
        $user = new User(['name' => $name, 'email' => 'mail@example.test']);
        $user->id = 124;
        $message = (new VerifyEmail)->toMail($user);
        $html = (string) $message->render();
        $this->assertStringContainsString(e($name), $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="https://outside.example.test"', $html);
        $this->assertStringContainsString('30 minutes', $html);
        parse_str(parse_url($message->actionUrl, PHP_URL_QUERY), $query);
        $this->assertSame((string) now()->addMinutes(30)->timestamp, $query['expires']);
        $this->assertTrue(URL::hasValidSignature(Request::create($message->actionUrl)));
        $this->travel(31)->minutes();
        $this->assertFalse(URL::hasValidSignature(Request::create($message->actionUrl)));
    }

    public function test_confirmation_plain_text_preserves_the_personalized_name_and_signed_link(): void
    {
        $user = new User(['name' => 'Camille', 'email' => 'mail@example.test']);
        $user->id = 125;
        $message = (new VerifyEmail)->toMail($user);
        $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
        $this->assertStringContainsString('Bonjour Camille,', $text);
        $this->assertStringContainsString('Confirmer mon courriel', $text);
        $this->assertStringContainsString($message->actionUrl, $text);
        $this->assertStringContainsString('60 minutes', $text);
    }

    public function test_password_notification_preserves_token_email_and_expiry_copy(): void
    {
        $user = new User(['name' => 'Camille', 'email' => 'camille+demo@example.test']);
        $message = (new ResetPassword('synthetic-preview-token'))->toMail($user);
        $html = (string) $message->render();
        $this->assertTheme($html);
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $html);
        $this->assertStringContainsString('L’équipe EquitAb', $html);
        $this->assertStringContainsString('href="'.e($message->actionUrl).'"', $html);
        $this->assertStringContainsString('/reset-password/synthetic-preview-token', $message->actionUrl);
        parse_str(parse_url($message->actionUrl, PHP_URL_QUERY), $query);
        $this->assertSame($user->email, $query['email']);
        $this->assertStringContainsString((string) config('auth.passwords.users.expire'), $html);
        $this->savePreview('reset-password', $html);
    }

    public function test_notification_plain_text_keeps_its_action_link(): void
    {
        $user = new User(['email' => 'camille@example.test']);
        $message = (new ResetPassword('synthetic-preview-token'))->toMail($user);
        $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
        $this->assertStringContainsString($message->actionUrl, $text);
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $text);
    }

    public function test_long_names_and_missing_renewal_date_keep_a_readable_fallback(): void
    {
        $name = str_repeat('Abonnement familial ', 12);
        $mail = $this->example('payment-confirmed', $name);
        $mail->member->next_payment_at = null;
        $html = $mail->render();
        $this->assertStringContainsString($name, $html);
        $this->assertStringContainsString('Consultez votre espace', $html);
        $this->assertStringContainsString('overflow-wrap', $html);
        $this->savePreview('long-content', $html);
    }

    private function assertTheme(string $html): void
    {
        $this->assertStringContainsString('https://equitab.example/Images/EquitabLogo.png', $html);
        $this->assertStringContainsString('alt="EquitAb"', $html);
        $this->assertStringContainsString('#187a57', $html);
        $this->assertStringContainsString('#303b37', $html);
        $this->assertStringContainsString('#f6f8f6', $html);
        $this->assertStringContainsString('max-width:480px', str_replace(' ', '', $html));
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringNotContainsString('EquitabLogoblanc', $html);
        $this->assertStringNotContainsString('#0b1929', strtolower($html));
        $this->assertLessThan(102400, strlen($html), 'Avoid oversized messages and mail-client clipping.');
    }

    private function example(string $name, string $person): Mailable
    {
        $user = new User(['name' => $person, 'email' => 'camille@example.test']);
        $service = new Subscription(['name' => 'Service Démo', 'currency' => 'CAD']);
        $group = (new Group(['name' => 'Les découvertes du mois', 'max_members' => 4, 'current_members' => 3, 'currency' => 'CAD']))
            ->setRelation('owner', $user)->setRelation('subscription', $service);
        $member = (new GroupMember(['share_amount' => 600, 'next_payment_at' => now()->addDays(3)]))
            ->setRelation('user', $user)->setRelation('group', $group);
        $payment = (new Payment(['amount' => 600, 'currency' => 'CAD']))->setRelation('group', $group);

        return match ($name) {
            'welcome' => new WelcomeUser($user),
            'payment-confirmed' => new PaymentConfirmed($payment, $member),
            'payment-failed' => new PaymentFailed($member),
            'new-member' => new NewMemberJoined($group, $user, 406),
            'renewal' => new RenewalReminder($member),
            'price-decrease' => new PriceChanged($member, 900, 600),
            'price-increase' => new PriceChanged($member, 600, 900),
            'refund' => new AutoRefundProcessed($payment, $user),
            'message' => new NewMessage($user, $user, $group->name, 'Bonjour ! Les informations sont disponibles dans votre groupe.', 1),
            'identity' => new IdentityVerified($user),
            'connect' => new ConnectAccountActivated($user),
            'admin' => new AdminMessage($user, 'Votre compte EquitAb', "Merci de nous avoir écrit.\nVous pouvez retrouver votre groupe dans votre espace EquitAb."),
        };
    }

    private function savePreview(string $name, string $html): void
    {
        // Opt-in local fixtures only. Never expose these previews as application routes.
        if (getenv('EQUITAB_EMAIL_PREVIEW') !== '1') {
            return;
        }
        $directory = base_path('output/communication-2026-10-06/emails');
        if (! is_dir($directory.'/Images')) {
            mkdir($directory.'/Images', 0755, true);
        }
        copy(public_path('Images/EquitabLogo.png'), $directory.'/Images/EquitabLogo.png');
        // Only the public logo points to this preview server; action URLs stay inert examples.
        file_put_contents($directory.'/'.$name.'.html', str_replace('https://equitab.example/Images/', './Images/', $html));
    }
}
