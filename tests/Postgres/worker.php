<?php

require __DIR__.'/bootstrap.php';

use App\Features\Group\Contracts\GroupProductGateway;
use App\Features\Group\Exceptions\PublicationUnavailable;
use App\Features\Group\Services\GroupDraftService;
use App\Features\Group\Services\PublishGroupDraft;
use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Services\OwnerConnectWebhookService;
use App\Features\Payment\Services\OwnerCountrySettings;
use App\Features\Payment\Services\OwnerOnboardingException;
use App\Features\Payment\Services\OwnerOnboardingService;
use App\Models\GroupDraft;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Postgres\PostgresEnvironment;
use Tests\Postgres\WorkerGateway;

try {
    $app = PostgresEnvironment::boot();
    [$script, $name, $action, $ownerId, $draftId, $json] = $argv;
    $options = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    config(['payments.eurozone_connect_enabled' => $options['eurozone'] ?? false]);
    DB::select("SELECT set_config('application_name', ?, false)", ['equitab-qa-'.$name]);
    $gateway = new WorkerGateway($name, $options);
    $app->instance(GroupProductGateway::class, $gateway);
    $app->instance(OwnerStripeGatewayInterface::class, $gateway);
    $owner = User::findOrFail($ownerId);
    DB::table('qa_signals')->insertOrIgnore(['key' => 'started:'.$name]);

    $result = match ($action) {
        'create' => ['draft_id' => app(GroupDraftService::class)->create($owner, $draftId, $options['data'])->id],
        'save' => ['version' => app(GroupDraftService::class)->save($owner,
            GroupDraft::findOrFail($draftId), $options['version'] ?? 1, $options['data'])->version],
        'publish' => ['group_id' => app(PublishGroupDraft::class)->publish($owner,
            GroupDraft::findOrFail($draftId), $options['version'] ?? 1, [])->id],
        'connect' => ['url' => app(OwnerOnboardingService::class)->start($owner)],
        'country' => ['country' => app(OwnerCountrySettings::class)->select($owner, $options['country'])['country']],
        'profile' => (function () use ($owner, $options): array {
            app(OwnerCountrySettings::class)->updateProfile($owner, $options['data']);

            return ['updated' => true];
        })(),
        'webhook' => (function () use ($owner, $options): array {
            app(OwnerConnectWebhookService::class)->synchronize($options['event'], $owner->stripe_connect_account_id);

            return ['synchronized' => true];
        })(),
        default => throw new LogicException('Unsupported test action.'),
    };
    $result = ['status' => 200, ...$result];
} catch (PublicationUnavailable|OwnerOnboardingException $e) {
    $result = ['status' => 503];
} catch (ValidationException $e) {
    $result = ['status' => 422];
} catch (AuthorizationException $e) {
    $result = ['status' => 403];
} catch (ModelNotFoundException $e) {
    $result = ['status' => 404];
} catch (HttpExceptionInterface $e) {
    $result = ['status' => $e->getStatusCode()];
} catch (QueryException $e) {
    $result = ['status' => 500, 'sqlstate' => $e->errorInfo[0] ?? null];
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}

echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
