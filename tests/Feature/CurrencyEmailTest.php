<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AutoRefundProcessed;
use App\Mail\NewMemberJoined;
use App\Mail\PaymentConfirmed;
use App\Mail\PaymentFailed;
use App\Mail\PriceChanged;
use App\Mail\RenewalReminder;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;
use TypeError;

class CurrencyEmailTest extends TestCase
{
    private string $isolatedRoot;

    public function createApplication(): Application
    {
        $this->isolatedRoot = sys_get_temp_dir().'/equitab-currency-email-'.bin2hex(random_bytes(12));
        mkdir($this->isolatedRoot, 0700);

        // Only the real rendering/model providers are needed. Never bootstrap
        // application configuration, cached configuration, or any .env file.
        $app = new Application(dirname(__DIR__, 2));
        $app->useStoragePath($this->isolatedRoot);
        $app->useEnvironmentPath($this->isolatedRoot);
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'app' => ['url' => 'https://equitab.example', 'locale' => 'fr', 'fallback_locale' => 'fr'],
            'view' => ['paths' => [resource_path('views')], 'compiled' => $this->isolatedRoot],
            'database' => ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ]],
            'mail' => ['default' => 'array', 'mailers' => ['array' => ['transport' => 'array']],
                'from' => ['address' => 'preview@example.test', 'name' => 'EquitAb']],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'session' => ['driver' => 'array'],
            'queue' => ['default' => 'null', 'connections' => ['null' => ['driver' => 'null']]],
            'broadcasting' => ['default' => 'null', 'connections' => ['null' => ['driver' => 'null']]],
            'filesystems' => ['default' => 'local', 'disks' => [
                'local' => ['driver' => 'local', 'root' => $this->isolatedRoot],
            ]],
        ]));
        Facade::setFacadeApplication($app);
        foreach ([DatabaseServiceProvider::class, FilesystemServiceProvider::class,
            ViewServiceProvider::class, TranslationServiceProvider::class, MailServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->boot();
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame(['array'], array_keys(config('mail.mailers')));
        $this->assertSame($this->isolatedRoot, storage_path());
        Http::preventStrayRequests();
        Mail::fake();
        $original = ApiRequestor::httpClient();
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldReceive('request')->andThrow(new \RuntimeException('Stripe network forbidden in currency email tests.'));
        ApiRequestor::setHttpClient($transport);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
        $this->beforeApplicationDestroyed(function (): void {
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
            Http::assertNothingSent();
            (new Filesystem)->deleteDirectory($this->isolatedRoot);
        });
    }

    public static function amounts(): array
    {
        return [
            'zero CAD' => [0, 'CAD', '0,00 $ CAD'],
            'cent CAD' => [1, 'CAD', '0,01 $ CAD'],
            'zero EUR' => [0, 'EUR', '0,00 € EUR'],
            'cent EUR' => [1, 'EUR', '0,01 € EUR'],
            'cents preserved' => [406, 'CAD', '4,06 $ CAD'],
            'lowercase normalized' => [123456, 'eur', '1 234,56 € EUR'],
            'CAD grouped' => [123456789, 'cad', '1 234 567,89 $ CAD'],
            'negative cent' => [-1, 'EUR', '-0,01 € EUR'],
            'negative CAD' => [-123456, 'CAD', '-1 234,56 $ CAD'],
            'integer maximum' => [PHP_INT_MAX, 'EUR', PHP_INT_SIZE === 8 ? '92 233 720 368 547 758,07 € EUR' : '21 474 836,47 € EUR'],
            'integer minimum' => [PHP_INT_MIN, 'CAD', PHP_INT_SIZE === 8 ? '-92 233 720 368 547 758,08 $ CAD' : '-21 474 836,48 $ CAD'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_formatter_preserves_every_cent_in_french(int $amount, string $currency, string $expected): void
    {
        $this->assertSame($expected, MoneyFormatter::format($amount, $currency));
    }

    public static function invalidCurrencies(): array
    {
        return [[''], ['USD'], ['€'], ['not-a-currency'], ['<script>CAD</script>']];
    }

    #[DataProvider('invalidCurrencies')]
    public function test_formatter_rejects_missing_or_unsupported_currency(string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyFormatter::format(100, $currency);
    }

    public function test_formatter_rejects_a_null_currency(): void
    {
        $this->expectException(TypeError::class);
        MoneyFormatter::format(100, null);
    }

    public static function invalidAmounts(): array
    {
        return [[1.5], ['100'], [null], [INF], [PHP_INT_MAX * 2.0]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_formatter_requires_integer_minor_units(mixed $amount): void
    {
        $this->expectException(TypeError::class);
        MoneyFormatter::format($amount, 'EUR');
    }

    public static function mailCurrencies(): iterable
    {
        foreach (['CAD', 'EUR'] as $currency) {
            foreach (['confirmed', 'refund', 'failed', 'renewal', 'joined', 'decrease', 'increase'] as $name) {
                yield $name.' '.$currency => [$name, $currency];
            }
        }
    }

    #[DataProvider('mailCurrencies')]
    public function test_rendered_amount_uses_its_native_currency_and_keeps_the_theme_and_links(string $name, string $currency): void
    {
        $otherCurrency = $currency === 'CAD' ? 'EUR' : 'CAD';
        [$group, $member, $payment, $user] = $this->fixtures($currency, $currency, $otherCurrency);
        // Deliberately contradict the current group for historical messages:
        // the original payment must remain the sole currency source.
        if (in_array($name, ['confirmed', 'refund'], true)) {
            $group->currency = $otherCurrency;
        }
        $unsafe = '<script>alert("mail")</script> & Camille';
        $user->name = $unsafe;
        $group->name = $unsafe;
        $group->subscription->name = $unsafe;
        $mail = $this->example($name, $group, $member, $payment, $user);
        $html = $mail->render();
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = $currency === 'CAD' ? '$ CAD' : '€ EUR';
        $this->assertStringContainsString('12,34 '.$label, $text);
        $this->assertStringNotContainsString($otherCurrency, $text);
        if (in_array($name, ['increase', 'decrease'], true)) {
            $this->assertStringContainsString('23,45 '.$label, $text);
            $this->assertStringContainsString($name === 'decrease' ? 'Votre part diminue.' : 'Votre part a changé.', $text);
            $this->assertSame($name === 'decrease' ? 2345 : 1234, $mail->oldPrice);
            $this->assertSame($name === 'decrease' ? 1234 : 2345, $mail->newPrice);
        }
        $this->assertStringContainsString(e($unsafe), $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('{{', $html);
        foreach ($mail->content()->with as $key => $value) {
            if (str_ends_with($key, 'Url')) {
                $this->assertStringContainsString('href="'.e($value).'"', $html);
            }
        }
        foreach (['alt="EquitAb"', 'https://equitab.example/Images/EquitabLogo.png', '#187a57', '#303b37', '#f6f8f6', 'overflow-wrap', 'Montserrat, Arial, sans-serif'] as $theme) {
            $this->assertStringContainsString($theme, $html);
        }
        $this->assertLessThan(102400, strlen($html));
        $this->assertSame(1234, $payment->amount);
        $this->assertSame(1234, $member->share_amount);

        // Optional synthetic previews, only in an explicitly allocated temp directory.
        $previewRoot = getenv('EQUITAB_CURRENCY_EMAIL_PREVIEW_ROOT');
        if (is_string($previewRoot) && $previewRoot !== '') {
            $this->assertMatchesRegularExpression('~^/(private/)?tmp/equitab-currency-email-preview\.[A-Za-z0-9]+$~D', $previewRoot);
            $this->assertDirectoryExists($previewRoot);
            $this->assertFalse(is_link($previewRoot));
            $clean = str_repeat('Abonnement familial ', 8);
            $user->name = 'Camille';
            $group->name = $clean;
            $group->subscription->name = 'Service Démo';
            $payment->amount = $member->share_amount = 123456789;
            $preview = $this->example($name, $group, $member, $payment, $user)->render();
            // Inline only the local logo and omit remote fonts in these previews.
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('Images/EquitabLogo.png')));
            $preview = str_replace('https://equitab.example/Images/EquitabLogo.png', $logo, $preview);
            $preview = preg_replace('~<link[^>]+https://fonts\.googleapis\.com[^>]*>~', '', $preview);
            $preview = preg_replace('~@import url\(https://fonts\.googleapis\.com[^;]+;~', '', $preview);
            file_put_contents($previewRoot.'/'.$name.'-'.strtolower($currency).'.html', $preview);
        }
    }

    #[DataProvider('mailCurrencies')]
    public function test_missing_native_currency_is_not_replaced_by_the_catalogue_or_cad(string $name, string $currency): void
    {
        [$group, $member, $payment, $user] = $this->fixtures($currency, $currency, 'CAD');
        if (in_array($name, ['confirmed', 'refund'], true)) {
            $payment->currency = null;
        } else {
            $group->currency = null;
        }
        $this->expectException(TypeError::class);
        $this->example($name, $group, $member, $payment, $user)->content();
    }

    public function test_new_member_constructor_keeps_both_the_calculated_and_explicit_amount_contract(): void
    {
        [$group, , , $user] = $this->fixtures('EUR', 'EUR', 'CAD');
        $calculated = Mockery::mock(Group::class)->makePartial();
        $calculated->setRawAttributes($group->getAttributes());
        $calculated->setRelations($group->getRelations());
        $calculated->shouldReceive('calculateCurrentPricePerMember')->once()->andReturn(719);
        $this->assertSame('7,19 € EUR', (new NewMemberJoined($calculated, $user))->content()->with['pricePerMember']);
        $this->assertSame('0,00 € EUR', (new NewMemberJoined($calculated, $user, 0))->content()->with['pricePerMember']);
    }

    public function test_payment_history_ignores_later_catalogue_changes_and_keeps_the_date_fallback(): void
    {
        [$group, $member, $payment, $user] = $this->fixtures('CAD', 'EUR', 'CAD');
        $member->next_payment_at = null;
        $group->subscription->currency = 'EUR';
        $group->subscription->price = 999999;
        $this->assertSame('12,34 € EUR', (new PaymentConfirmed($payment, $member))->content()->with['amount']);
        $this->assertSame('12,34 € EUR', (new AutoRefundProcessed($payment, $user))->content()->with['amount']);
        $this->assertStringContainsString('Consultez votre espace', (new PaymentConfirmed($payment, $member))->render());
    }

    private function fixtures(string $groupCurrency, string $paymentCurrency, string $catalogueCurrency): array
    {
        $user = new User(['name' => 'Camille', 'email' => 'camille@example.test']);
        $service = new Subscription(['name' => 'Service Démo', 'currency' => $catalogueCurrency, 'price' => 9999]);
        $group = (new Group(['name' => 'Les découvertes du mois', 'max_members' => 4, 'current_members' => 3]))
            ->setRelation('owner', $user)->setRelation('subscription', $service);
        $group->currency = $groupCurrency;
        $member = (new GroupMember(['share_amount' => 1234, 'next_payment_at' => '2026-10-10']))
            ->setRelation('user', $user)->setRelation('group', $group);
        $payment = (new Payment(['amount' => 1234, 'currency' => $paymentCurrency]))->setRelation('group', $group);

        return [$group, $member, $payment, $user];
    }

    private function example(string $name, Group $group, GroupMember $member, Payment $payment, User $user): Mailable
    {
        return match ($name) {
            'confirmed' => new PaymentConfirmed($payment, $member),
            'refund' => new AutoRefundProcessed($payment, $user),
            'failed' => new PaymentFailed($member),
            'renewal' => new RenewalReminder($member),
            'joined' => new NewMemberJoined($group, $user, $member->share_amount),
            'decrease' => new PriceChanged($member, 2345, 1234),
            'increase' => new PriceChanged($member, 1234, 2345),
        };
    }
}
