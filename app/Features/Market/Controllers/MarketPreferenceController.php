<?php

namespace App\Features\Market\Controllers;

use App\Features\Market\Services\MarketPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MarketPreferenceController
{
    public function __invoke(Request $request, MarketPreference $market): RedirectResponse
    {
        $data = $request->validate(['country' => ['required', 'string', 'size:2']]);
        $market->select($request, $data['country']);

        return back(303)->with('success', 'Pays de découverte enregistré. Vos abonnements existants restent inchangés.');
    }
}
