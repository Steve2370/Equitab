<?php

namespace App\Features\Subscription\Controllers;

use App\Features\Market\Services\MarketPreference;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(Request $request, MarketPreference $market): Response
    {
        $currency = $market->currency($request);
        $categories = SubscriptionCategory::with(['subscriptions' => function ($q) {
            $q->where('is_active', true)->orderBy('name');
        }])->get()->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'subscriptions' => $cat->subscriptions->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                // A native CAD catalogue price is not a EUR quote. No invented FX rate.
                'monthly_price' => $currency === null || $currency === $s->currency ? $s->monthly_price : null,
                'currency' => $currency ?? $s->currency,
                'max_members' => $s->max_members,
            ]),
        ]);

        return Inertia::render('Services', [
            'categories' => $categories,
        ]);
    }
}
