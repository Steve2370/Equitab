<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() ||
            ! ($request->user() instanceof MustVerifyEmail) ||
            ! $request->user()->hasVerifiedEmail()) {
            // JSON endpoints retain their refusal contract, even with HTML headers.
            if ($request->is('api/*', 'group-drafts', 'group-drafts/*')
                || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json(['message' => 'Veuillez confirmer votre adresse courriel.'], 409);
            }

            // Remember only an internal GET page, never a rejected mutation or an
            // untrusted return URL. An existing invitation destination takes priority.
            if ($request->isMethod('GET') && $request->hasSession()
                && ! $request->session()->has('url.intended')) {
                $path = '/'.ltrim($request->getPathInfo(), '/');
                $query = $request->getQueryString();
                $request->session()->put('url.intended', $path.($query ? '?'.$query : ''));
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
