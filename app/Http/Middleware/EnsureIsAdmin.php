<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureIsAdmin
{
    private const ADMIN_EMAILS = [
        'briceyouatchui@gmail.com',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || ! in_array($request->user()->email, self::ADMIN_EMAILS)) {
            abort(403, 'Accès refusé.');
        }

        return $next($request);
    }
}
