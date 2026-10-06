<?php

namespace Tests\Postgres;

use App\Models\GroupDraft;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

abstract class PostgresTestCase extends TestCase
{
    /** @var list<Process> */
    private array $workers = [];

    public function createApplication(): Application
    {
        return PostgresEnvironment::boot();
    }

    protected function setUp(): void
    {
        parent::setUp();
        PostgresEnvironment::guard();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Schema::create('qa_signals', function (Blueprint $table): void {
            $table->string('key')->primary();
        });
        Schema::create('qa_products', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        Schema::create('qa_accounts', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('id');
            $table->json('parameters');
        });
        Schema::create('qa_remote_accounts', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->boolean('active')->default(true);
        });
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    protected function readyOwner(array $attributes = []): User
    {
        return User::factory()->create([
            'identity_status' => 'verified',
            'stripe_connect_status' => 'active',
            'stripe_connect_account_id' => 'acct_pg_'.bin2hex(random_bytes(5)),
            'stripe_identity_session_id' => 'vs_pg_'.bin2hex(random_bytes(5)),
            ...$attributes,
        ]);
    }

    protected function validData(?Subscription $subscription = null): array
    {
        $subscription ??= Subscription::factory()->create(['max_members' => 6]);

        return [
            'subscription_id' => $subscription->id, 'name' => 'Groupe PostgreSQL de démonstration',
            'description' => null, 'tier' => 'standard', 'max_members' => 4,
            'total_price' => 1999, 'split_type' => 'equal', 'visibility' => 'public',
            'renewal_date' => now()->addMonth()->toDateString(), 'auto_renew' => true,
        ];
    }

    protected function draftFor(User $owner, ?array $data = null): GroupDraft
    {
        $draft = new GroupDraft;
        $draft->forceFill(['owner_id' => $owner->id, 'data' => $data ?? $this->validData(), 'version' => 1, 'status' => 'draft'])->save();

        return $draft;
    }

    protected function worker(string $name, string $action, User $owner, ?GroupDraft $draft = null, array $options = []): Process
    {
        $process = new Process([
            PHP_BINARY, __DIR__.'/worker.php', $name, $action, (string) $owner->id,
            $draft?->id ?? '', json_encode($options, JSON_THROW_ON_ERROR),
        ], base_path());
        $process->setTimeout(25);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    protected function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());

        return json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function awaitSignal(string $key): void
    {
        $this->await(fn () => DB::table('qa_signals')->where('key', $key)->exists(), 'Missing worker signal '.$key);
    }

    protected function release(string $name): void
    {
        DB::table('qa_signals')->insertOrIgnore(['key' => 'release:'.$name]);
    }

    protected function await(callable $predicate, string $message): void
    {
        $deadline = microtime(true) + 8;
        while (! $predicate()) {
            if (microtime(true) >= $deadline) {
                $this->fail($message);
            }
            usleep(20000);
        }
        $this->assertTrue(true);
    }
}
