<?php

require dirname(__DIR__).'/Postgres/bootstrap.php';

use App\Features\Auth\Services\SocialAuthService;
use App\Mail\WelcomeUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as OAuthUser;
use Tests\Postgres\PostgresEnvironment;

try {
    PostgresEnvironment::boot();
    Mail::fake();
    Notification::fake();
    [$script, $name, $identityId, $email, $pauseCreation] = $argv;
    DB::select("SELECT set_config('application_name', ?, false)", ['equitab-social-qa-'.$name]);
    DB::table('qa_signals')->insert(['key' => 'started:'.$name]);
    if ($pauseCreation === 'yes') {
        // The barrier must be committed outside the authentication transaction.
        config(['database.connections.social_qa_control' => config('database.connections.pgsql')]);
        $control = DB::connection('social_qa_control');
        $paused = false;
        User::creating(function () use ($name, $control, &$paused): void {
            if ($paused) {
                return;
            }
            $paused = true;
            $control->table('qa_signals')->insert(['key' => 'creating:'.$name]);
            $deadline = microtime(true) + 8;
            while (! $control->table('qa_signals')->where('key', 'release:'.$name)->exists()) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Social authentication barrier timed out.');
                }
                usleep(20000);
            }
        });
    }
    $identity = (new OAuthUser)->setRaw(['email_verified' => true])->map([
        'id' => $identityId, 'email' => $email, 'name' => 'Synthetic concurrent member',
    ]);
    $user = app(SocialAuthService::class)->findOrCreateUser('google', $identity, 'BE');
    $result = ['status' => 200, 'user_id' => $user->id, 'welcome_count' => Mail::sent(WelcomeUser::class)->count()];
} catch (ValidationException) {
    $result = ['status' => 422, 'welcome_count' => Mail::sent(WelcomeUser::class)->count()];
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}

echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
