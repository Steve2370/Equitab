<?php

namespace App\Features\Auth\Middleware;

use App\Features\Auth\Services\AccountSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsCurrent
{
    public function __construct(private readonly AccountSession $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! ($user = Auth::guard('web')->user())) {
            return $next($request);
        }

        if (Auth::guard('web')->viaRemember()) {
            // The guard has already checked the rotated remember token.
            $this->sessions->remember($request->session(), $user);
        }

        if (! $this->sessions->isCurrent($request->session(), $user)) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->is('api/*', 'group-drafts', 'group-drafts/*')) {
                return response()->json(['message' => 'Votre session a expiré. Veuillez vous reconnecter.'], 401);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }
}
