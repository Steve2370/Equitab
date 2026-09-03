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

        if ($user && $user->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('error', 'Votre compte a été suspendu.');
        }

        return $next($request);
    }
}
