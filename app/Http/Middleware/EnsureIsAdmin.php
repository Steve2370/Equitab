<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureIsAdmin
{
    // Rendu public pour que HandleInertiaRequests puisse réutiliser exactement
    // la même liste (isAdmin partagé au frontend) plutôt que de maintenir une
    // seconde copie divergente — c'était le cas auparavant : deux listes
    // codées en dur, faciles à désynchroniser en n'en mettant à jour qu'une.
    // Idéalement une colonne is_admin en base remplacerait cette liste (voir
    // rapport d'audit) ; en attendant, au moins une seule source de vérité.
    public const ADMIN_EMAILS = [
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
