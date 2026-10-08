<?php

use App\Features\Auth\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureIsAdmin;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Inertia\Inertia;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureSessionIsCurrent::class,
            EnsureNotSuspended::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'admin' => EnsureIsAdmin::class,
            'not_suspended' => EnsureNotSuspended::class,
            'session_current' => EnsureSessionIsCurrent::class,
        ]);

        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if ($request->routeIs('verification.verify')
                && ($request->header('X-Inertia') || ! $request->expectsJson())) {
                return redirect()->route('verification.notice')->with('status', 'verification-link-invalid');
            }
        });
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->routeIs('verification.send')
                && ($request->header('X-Inertia') || ! $request->expectsJson())) {
                return redirect()->route('verification.notice')->withErrors([
                    'verification' => 'Trop de demandes. Patientez un moment avant de demander un nouveau courriel.',
                ]);
            }
        });
        // These endpoints are JSON even for a handcrafted HTML-form request.
        // Never flash an unvalidated draft payload (including nested secrets).
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('group-drafts', 'group-drafts/*', 'api/group-drafts', 'api/group-drafts/*', 'api/groups/*/service-access', 'api/groups/*/service-access/revoke')
            || $request->expectsJson()
        );
        $exceptions->dontFlash([
            'credential_email', 'credential_password', 'credential_notes',
            'data.credential_email', 'data.credential_password', 'data.credential_notes',
            'invitation_url',
        ]);
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            // Keep the draft API contract in production too, including expired
            // sessions. An HTML redirect must never masquerade as a saved draft.
            if ($request->expectsJson() || $request->is('api/*', 'group-drafts', 'group-drafts/*')) {
                return $response;
            }
            if (
                ! app()->environment(['local', 'testing'])
                && in_array($response->getStatusCode(), [404, 500, 503])
            ) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            } elseif ($response->getStatusCode() === 419) {
                return back()->with('error', 'La page a expiré, veuillez réessayer.');
            }

            return $response;
        });
    })
    ->create();
