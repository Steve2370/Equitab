<?php

namespace App\Features\Payment\Controllers;

use App\Features\Payment\Services\OwnerCountrySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnerCountryController
{
    public function __invoke(Request $request, OwnerCountrySettings $settings): JsonResponse
    {
        $data = $request->validate(['country' => ['required', 'string', 'size:2']]);

        return response()->json($settings->select($request->user(), $data['country']));
    }
}
