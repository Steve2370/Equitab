<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureNotSuspended
{
    /**
     * Un admin doit pouvoir suspendre un compte tout de suite, pas
     * seulement empêcher sa prochaine connexion — sans ce middleware, un
     * utilisateur déjà connecté garderait l'accès jusqu'à expiration
     * naturelle de sa session.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->canAccessAccount()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson() || $request->is('api/*', 'group-drafts', 'group-drafts/*')) {
                return response()->json(['message' => 'Ce compte est suspendu ou désactivé.'], 403);
            }

            return redirect('/login')->with('error', 'Ce compte est suspendu ou désactivé.');
        }

        return $next($request);
    }
}
