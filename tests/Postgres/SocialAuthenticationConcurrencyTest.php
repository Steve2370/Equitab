<?php

namespace Tests\Postgres;

use App\Features\Auth\Services\SocialAuthService;
use App\Models\OauthProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Two\User as OAuthUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class SocialAuthenticationConcurrencyTest extends PostgresTestCase
{
    /** @var list<Process> */
    private array $socialWorkers = [];

    protected function tearDown(): void
    {
        foreach ($this->socialWorkers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
        parent::tearDown();
    }

    private function socialWorker(string $name, string $identity = 'concurrent-google', string $email = 'concurrent@example.test', bool $pauseCreation = false): Process
    {
        $worker = new Process([
            PHP_BINARY, base_path('tests/Support/social-auth-concurrency-worker.php'),
            $name, $identity, $email, $pauseCreation ? 'yes' : 'no',
        ], base_path());
        $worker->setTimeout(25);
        $worker->start();
        $this->socialWorkers[] = $worker;

        return $worker;
    }

    public function test_parallel_returns_after_deletion_share_one_fresh_account(): void
    {
        $old = User::factory()->create(['email' => 'concurrent@example.test', 'country' => 'CA', 'is_admin' => true]);
        $link = $old->oauthProviders()->create(['provider' => 'google', 'provider_id' => 'concurrent-google']);
        $old->delete();
        $before = User::withTrashed()->findOrFail($old->id)->getRawOriginal();

        DB::beginTransaction();
        OauthProvider::whereKey($link->id)->lockForUpdate()->firstOrFail();
        $a = $this->socialWorker('deleted-a');
        $b = $this->socialWorker('deleted-b');
        $this->await(function (): bool {
            DB::select('SELECT pg_stat_clear_snapshot()');

            return (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity
                WHERE application_name IN ('equitab-social-qa-deleted-a', 'equitab-social-qa-deleted-b')
                AND wait_event_type = 'Lock'")->count === 2;
        }, 'Both callbacks must wait for the existing identity row.');
        DB::commit();

        $first = $this->workerResult($a);
        $second = $this->workerResult($b);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame($first['user_id'], $second['user_id']);
        $this->assertNotSame($old->id, $first['user_id']);
        $this->assertSame(1, $first['welcome_count'] + $second['welcome_count']);
        $this->assertSame(1, User::count());
        $this->assertSame(2, User::withTrashed()->count());
        $this->assertDatabaseCount('oauth_providers', 1);
        $this->assertSame($first['user_id'], $link->fresh()->user_id);
        $this->assertEquals($before, User::withTrashed()->findOrFail($old->id)->getRawOriginal());
        $this->assertFalse(User::findOrFail($first['user_id'])->is_admin);
    }

    public static function firstLoginRaces(): array
    {
        return [
            'same identity and email' => ['concurrent-google', 'concurrent@example.test', 200],
            'same identity changed email' => ['concurrent-google', 'changed@example.test', 200],
            'different identity same email' => ['different-google', 'concurrent@example.test', 422],
        ];
    }

    #[DataProvider('firstLoginRaces')]
    public function test_parallel_first_logins_keep_identity_uniqueness(string $secondIdentity, string $secondEmail, int $loserStatus): void
    {
        $a = $this->socialWorker('first-a', pauseCreation: true);
        $b = $this->socialWorker('first-b', $secondIdentity, $secondEmail, true);
        $this->awaitSignal('creating:first-a');
        $this->awaitSignal('creating:first-b');
        $this->release('first-a');
        $this->release('first-b');
        $results = [$this->workerResult($a), $this->workerResult($b)];
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, $loserStatus], $statuses);
        $this->assertSame(1, array_sum(array_column($results, 'welcome_count')));
        $this->assertSame(1, User::withTrashed()->count(), 'The losing transaction must not leave an orphan account.');
        $this->assertDatabaseCount('oauth_providers', 1);
        if ($loserStatus === 200) {
            $this->assertSame($results[0]['user_id'], $results[1]['user_id']);
        }
    }

    public function test_reassignment_is_rolled_back_with_the_transaction(): void
    {
        Mail::fake();
        Notification::fake();
        $old = User::factory()->create(['email' => 'rollback@example.test']);
        $link = $old->oauthProviders()->create(['provider' => 'google', 'provider_id' => 'rollback-google']);
        $old->delete();
        $identity = (new OAuthUser)->setRaw(['email_verified' => true])->map([
            'id' => 'rollback-google', 'email' => 'rollback@example.test', 'name' => 'Synthetic rollback',
        ]);
        DB::beginTransaction();
        $new = app(SocialAuthService::class)->findOrCreateUser('google', $identity);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($new->id, $link->fresh()->user_id);
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertSame($old->id, $link->fresh()->user_id);
        $this->assertSame(1, User::withTrashed()->count());
        Mail::assertNothingSent();
    }
}
