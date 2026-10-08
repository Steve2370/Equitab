<?php

namespace Tests\Feature;

use App\Features\Auth\Services\AccountSession;
use App\Features\Reports\Services\CurrencyReportService;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Postgres\PostgresEnvironment;
use Tests\TestCase;

class CurrencyReportsTest extends TestCase
{
    private array $originalEnvironment = [];

    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        // Each synthetic login has its own valid session, including when a
        // scenario switches from a member to an administrator.
        return $this->withSession([AccountSession::KEY => ['id' => $user->getAuthIdentifier(), 'version' => (int) $user->auth_version]]);
    }

    public function createApplication()
    {
        $root = sys_get_temp_dir().'/equitab-currency-reports-'.bin2hex(random_bytes(8));
        foreach (['env', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs'] as $directory) {
            mkdir($root.'/'.$directory, 0700, true);
        }
        $postgres = getenv('EQUITAB_PG_TEST_ROOT') !== false;
        if ($postgres && ! class_exists(PostgresEnvironment::class)) {
            throw new \RuntimeException('PostgreSQL reports require phpunit-postgres.xml and its guarded bootstrap.');
        }
        $environment = [
            'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'APP_CONFIG_CACHE' => $root.'/config.php', 'APP_ROUTES_CACHE' => $root.'/routes.php',
            'APP_SERVICES_CACHE' => $root.'/services.php', 'APP_PACKAGES_CACHE' => $root.'/packages.php',
            'VIEW_COMPILED_PATH' => $root.'/storage/framework/views',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
            'BCRYPT_ROUNDS' => '4', 'STRIPE_SECRET' => 'sk_test_reports_no_network', 'LOG_CHANNEL' => '"null"',
        ];
        if (! $postgres) {
            $environment += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''];
        }
        foreach ($environment as $name => $value) {
            $this->originalEnvironment[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($root.'/env');
        $app->useStoragePath($root.'/storage');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        if (getenv('EQUITAB_PG_TEST_ROOT') !== false) {
            PostgresEnvironment::guard();
        } else {
            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());
            $this->assertEmpty(config('database.connections.sqlite.url'));
        }
        // Remove other database connections and remote drivers before migrations.
        $driver = config('database.default');
        config([
            'database.connections' => [$driver => config('database.connections.'.$driver)],
            'database.redis' => [], 'cache.stores' => ['array' => ['driver' => 'array']],
            'mail.mailers' => ['array' => ['transport' => 'array']],
            'queue.connections' => ['sync' => ['driver' => 'sync']],
            'filesystems.default' => 'local',
            'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => storage_path('app')]],
        ]);
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertNull(config('broadcasting.default'));
        Http::preventStrayRequests();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        $original = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                throw new \LogicException('Stripe network forbidden in currency reports.');
            }
        });
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient($original));
        $this->withoutVite();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->travelTo(now()->setDate(2026, 10, 7)->startOfDay());
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            parent::tearDown();
        } finally {
            foreach ($this->originalEnvironment as $name => [$process, $env, $server]) {
                putenv($process === false ? $name : $name.'='.$process);
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    public function test_admin_separates_equal_native_amounts_and_persisted_fees(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $cad = $this->group('CAD');
        $eur = $this->group('EUR');
        $this->payment($cad, $admin, ['amount' => 1000, 'platform_fee_amount' => 17]);
        $this->payment($eur, $admin, ['amount' => 1000, 'platform_fee_amount' => 83]);
        $expected = [
            ['currency' => 'CAD', 'totalPayments' => 1, 'totalRevenue' => 1000, 'equitabEarnings' => 17],
            ['currency' => 'EUR', 'totalPayments' => 1, 'totalRevenue' => 1000, 'equitabEarnings' => 83],
        ];
        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.totalPayments', 2)->where('stats.paymentTotalsByCurrency', $expected)
            ->missing('stats.totalRevenue')->missing('stats.equitabEarnings'));
        $this->get('/admin/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('paymentTotalsByCurrency', $expected)->missing('totalEarnings')->where('payments.total', 2));
    }

    public function test_completed_status_and_original_gross_fees_are_preserved_for_refund_history(): void
    {
        $user = User::factory()->create();
        foreach (['CAD', 'EUR'] as $currency) {
            $group = $this->group($currency);
            // A partial refund remains completed in the current synchronization
            // contract; there is no persisted partial-refund amount to subtract.
            $this->payment($group, $user, ['amount' => 1301, 'platform_fee_amount' => 63]);
            $this->payment($group, $user, ['amount' => 499, 'platform_fee_amount' => null]);
            foreach (['refunded', 'failed', 'pending'] as $status) {
                $this->payment($group, $user, ['status' => $status, 'amount' => 9999, 'platform_fee_amount' => 777]);
            }
        }
        $this->assertSame([
            ['currency' => 'CAD', 'totalPayments' => 2, 'totalRevenue' => 1800, 'equitabEarnings' => 63],
            ['currency' => 'EUR', 'totalPayments' => 2, 'totalRevenue' => 1800, 'equitabEarnings' => 63],
        ], app(CurrencyReportService::class)->completedPayments(Payment::query())->all());
    }

    public function test_query_scope_and_date_boundaries_are_kept_without_mutating_the_callers_query(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (['CAD', 'EUR'] as $currency) {
            $group = $this->group($currency);
            $this->payment($group, $user, ['amount' => 100, 'paid_at' => '2026-10-01 00:00:00']);
            $this->payment($group, $user, ['amount' => 200, 'paid_at' => '2026-10-07 23:59:59']);
            $this->payment($group, $user, ['amount' => 999, 'paid_at' => '2026-09-30 23:59:59']);
            $this->payment($group, $user, ['amount' => 999, 'paid_at' => '2026-10-08 00:00:00']);
            $this->payment($group, $other, ['amount' => 999]);
        }
        $query = Payment::where('user_id', $user->id)->whereBetween('paid_at', ['2026-10-01 00:00:00', '2026-10-07 23:59:59'])->latest('paid_at');
        $sql = $query->toSql();
        $rows = app(CurrencyReportService::class)->completedPayments($query);
        $this->assertSame([300, 300], $rows->pluck('totalRevenue')->all());
        $this->assertSame(['CAD', 'EUR'], $rows->pluck('currency')->all());
        $this->assertSame($sql, $query->toSql());
    }

    public function test_monthly_budget_uses_group_currency_and_savings_require_the_same_catalogue_currency(): void
    {
        $user = User::factory()->create();
        $cad = $this->group('CAD', ['monthly_price' => 2400]);
        $eur = $this->group('EUR', ['currency' => 'CAD', 'monthly_price' => 9900]);
        GroupMember::factory()->for($cad)->for($user)->create(['share_amount' => 500]);
        GroupMember::factory()->for($eur)->for($user)->create(['share_amount' => 1000, 'role' => 'owner']);
        foreach (['pending_payment', 'suspended', 'left'] as $status) {
            GroupMember::factory()->for($this->group('EUR'))->for($user)->create(['share_amount' => 9999, 'status' => $status]);
        }
        GroupMember::factory()->for($this->group('EUR'))->create(['share_amount' => 9999]);
        $closed = $this->group('EUR', [], ['status' => 'closed']);
        GroupMember::factory()->for($closed)->for($user)->create(['share_amount' => 9999]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeSubscriptionsCount', 2)->where('monthlyTotalsByCurrency', [
                ['currency' => 'CAD', 'monthlySpend' => 500, 'totalSavings' => 1900, 'unavailableSavingsCount' => 0],
                ['currency' => 'EUR', 'monthlySpend' => 1000, 'totalSavings' => null, 'unavailableSavingsCount' => 1],
            ])->missing('monthlySpend')->missing('totalSavings'));
    }

    public function test_savings_keep_cents_negative_values_and_never_present_a_partial_total(): void
    {
        $user = User::factory()->create();
        $group = $this->group('EUR', ['monthly_price' => 999]);
        GroupMember::factory()->for($group)->for($user)->create(['share_amount' => 1001]);
        $reports = app(CurrencyReportService::class);
        $this->assertSame(-2, $reports->monthlyMemberships($user)['monthlyTotalsByCurrency'][0]['totalSavings']);
        $unmatched = $this->group('EUR', ['currency' => 'CAD']);
        GroupMember::factory()->for($unmatched)->for($user)->create(['share_amount' => 399]);
        $this->assertSame([
            ['currency' => 'EUR', 'monthlySpend' => 1400, 'totalSavings' => null, 'unavailableSavingsCount' => 1],
        ], $reports->monthlyMemberships($user)['monthlyTotalsByCurrency']->all());
    }

    public function test_archived_history_and_deleted_identity_keep_payment_currency_and_null_fee(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $group = $this->group('EUR', ['name' => 'Historical EUR service']);
        $payment = $this->payment($group, $user, ['amount' => 1577, 'platform_fee_amount' => null, 'paid_at' => null]);
        Dispute::create(['payment_id' => $payment->id, 'user_id' => $user->id, 'group_id' => $group->id, 'reason' => 'no_access']);
        GroupMember::factory()->for($group)->for($user)->create();
        $group->subscription->update(['currency' => 'CAD']);
        $group->owner->delete();
        $group->delete();

        $this->actingAs($user)->get('/dashboard/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payments.data.0.currency', 'EUR')->where('payments.data.0.groupName', 'Historical EUR service')
            ->where('payments.data.0.amount', 1577)->where('payments.data.0.paidAt', null)
            ->where('paidTotalsByCurrency', [['currency' => 'EUR', 'amount' => 1577]]));
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeSubscriptionsCount', 0)->has('monthlyTotalsByCurrency', 0));
        $this->actingAs($admin)->get('/admin/disputes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('disputes.data.0.currency', 'EUR')->where('disputes.data.0.amount', 1577)
            ->where('disputes.data.0.groupName', $group->name));
        $user->delete();
        $this->get('/admin/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payments.data.0.currency', 'EUR')->where('payments.data.0.equitabFee', null)
            ->where('payments.data.0.userName', 'Utilisateur supprimé'));
        $this->assertNull($payment->fresh()->group);
        $this->assertNull($payment->fresh()->user);
    }

    public function test_member_pagination_totals_are_per_page_and_never_include_another_user(): void
    {
        $user = User::factory()->create();
        $cad = $this->group('CAD');
        $eur = $this->group('EUR');
        for ($i = 0; $i < 21; $i++) {
            $this->payment($i % 2 ? $eur : $cad, $user, ['amount' => 1000, 'paid_at' => now()->subMinutes($i)]);
        }
        $this->payment($eur, User::factory()->create(), ['amount' => 77777]);
        $this->actingAs($user)->get('/dashboard/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payments.total', 21)->has('payments.data', 20)->where('paidTotalsByCurrency', [
                ['currency' => 'CAD', 'amount' => 10000], ['currency' => 'EUR', 'amount' => 10000],
            ]));
        $this->get('/dashboard/payments?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payments.total', 21)->has('payments.data', 1)->where('paidTotalsByCurrency', [['currency' => 'CAD', 'amount' => 1000]]));
    }

    public function test_admin_all_time_totals_are_not_limited_to_the_current_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $group = $this->group('EUR');
        for ($i = 0; $i < 21; $i++) {
            $this->payment($group, $admin, ['amount' => 101, 'platform_fee_amount' => 3, 'paid_at' => now()->subDays($i)]);
        }
        $this->actingAs($admin)->get('/admin/payments?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payments.total', 21)->has('payments.data', 1)->where('paymentTotalsByCurrency', [
                ['currency' => 'EUR', 'totalPayments' => 21, 'totalRevenue' => 2121, 'equitabEarnings' => 63],
            ]));
    }

    public function test_member_history_keeps_all_statuses_but_only_completed_native_subtotals(): void
    {
        $user = User::factory()->create();
        foreach (['CAD', 'EUR'] as $currency) {
            $group = $this->group($currency);
            foreach (['completed', 'refunded', 'pending', 'failed'] as $status) {
                $this->payment($group, $user, ['status' => $status, 'amount' => $status === 'completed' ? 123 : 99999]);
            }
        }
        $this->actingAs($user)->get('/dashboard/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payments.total', 8)->has('payments.data', 8)->where('paidTotalsByCurrency', [
                ['currency' => 'CAD', 'amount' => 123], ['currency' => 'EUR', 'amount' => 123],
            ]));
    }

    public function test_deleting_only_the_owner_does_not_remove_members_budget_or_reveal_identity(): void
    {
        $user = User::factory()->create();
        $group = $this->group('CAD', ['monthly_price' => 2400]);
        GroupMember::factory()->for($group)->for($user)->create(['share_amount' => 500, 'joined_at' => '2026-10-05']);
        $group->owner->delete();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeSubscriptionsCount', 1)->where('monthlyTotalsByCurrency', [
                ['currency' => 'CAD', 'monthlySpend' => 500, 'totalSavings' => 1900, 'unavailableSavingsCount' => 0],
            ]));
        $this->get('/dashboard/subscriptions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('joinedSubscriptions', 1)->where('joinedSubscriptions.0.ownerName', 'Utilisateur supprimé')
            ->where('joinedSubscriptions.0.joinedAt', '05/10/2026')->where('joinedSubscriptions.0.currency', 'CAD'));
    }

    public function test_upcoming_payments_retain_due_date_order_five_row_limit_and_archived_currency(): void
    {
        $user = User::factory()->create();
        $group = $this->group('EUR');
        for ($i = 6; $i >= 0; $i--) {
            $this->payment($group, $user, ['status' => 'pending', 'amount' => 100 + $i, 'paid_at' => null, 'due_date' => now()->addDays($i)]);
        }
        $this->payment($group, $user, ['status' => 'failed']);
        $this->payment($group, User::factory()->create(), ['status' => 'pending']);
        $group->delete();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('upcomingPayments', 5)->where('upcomingPayments.0.amount', 100)->where('upcomingPayments.4.amount', 104)
            ->where('upcomingPayments.0.currency', 'EUR')->where('upcomingPayments.0.paidAt', null));
    }

    public function test_group_rows_expose_native_currency_and_ownership_remains_scoped(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $group = $this->group('EUR', ['currency' => 'CAD'], ['owner_id' => $owner->id]);
        GroupMember::factory()->for($group)->for($member)->pendingPayment()->create();
        $this->group('CAD');
        $this->actingAs($owner)->get('/dashboard/subscriptions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('ownedSubscriptions', 1)->has('joinedSubscriptions', 0)->where('ownedSubscriptions.0.currency', 'EUR'));
        $this->actingAs($member)->get('/dashboard/subscriptions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('joinedSubscriptions', 1)->has('ownedSubscriptions', 0)->where('joinedSubscriptions.0.currency', 'EUR'));
        $this->actingAs($admin)->get('/admin/groups')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups.total', 2)->where('groups.data', fn ($rows) => collect($rows)->firstWhere('id', $group->id)['currency'] === 'EUR'));
    }

    public function test_empty_categories_and_legacy_default_cad_have_no_fabricated_cross_currency_total(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->has('stats.paymentTotalsByCurrency', 0));
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->has('monthlyTotalsByCurrency', 0));
        $this->get('/dashboard/payments')->assertOk()->assertInertia(fn (Assert $page) => $page->has('paidTotalsByCurrency', 0));
        $group = Group::factory()->create();
        $this->assertSame('CAD', $group->currency);
        $payment = Payment::factory()->for($group)->for($admin, 'user')->create(['platform_fee_amount' => null]);
        $this->assertSame([
            ['currency' => 'CAD', 'totalPayments' => 1, 'totalRevenue' => $payment->amount, 'equitabEarnings' => 0],
        ], app(CurrencyReportService::class)->completedPayments(Payment::query())->all());
    }

    public function test_reports_keep_guest_unverified_and_non_admin_authorization(): void
    {
        foreach (['/admin', '/admin/groups', '/admin/payments', '/admin/disputes', '/dashboard', '/dashboard/payments', '/dashboard/subscriptions'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $unverified = User::factory()->create(['email_verified_at' => null, 'is_admin' => true]);
        $this->actingAs($unverified)->get('/admin')->assertRedirect(route('verification.notice'));
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
        $member = User::factory()->create();
        foreach (['/admin', '/admin/groups', '/admin/payments', '/admin/disputes'] as $url) {
            $this->actingAs($member)->get($url)->assertForbidden();
        }
    }

    private function group(string $currency, array $catalogue = [], array $attributes = []): Group
    {
        $name = 'Report service '.bin2hex(random_bytes(6));
        $subscription = Subscription::create([
            'category_id' => SubscriptionCategory::factory()->create()->id,
            'name' => $name, 'slug' => str_replace(' ', '-', $name),
            'monthly_price' => 1999, 'currency' => $currency, 'max_members' => 6,
            'billing_cycle' => 'monthly', 'is_active' => true, 'is_verified' => true,
            ...$catalogue,
        ]);

        return Group::factory()->for($subscription)->create(['currency' => $currency, ...$attributes]);
    }

    private function payment(Group $group, User $user, array $attributes = []): Payment
    {
        return Payment::factory()->for($group)->for($user)->create(['currency' => $group->currency, ...$attributes]);
    }
}
