<?php

namespace App\Features\Subscription\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(): Response
    {
        $categories = SubscriptionCategory::with(['subscriptions' => function ($q) {
            $q->where('is_active', true)->orderBy('name');
        }])->get()->map(fn($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'subscriptions' => $cat->subscriptions->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'monthly_price' => $s->monthly_price,
                'max_members' => $s->max_members,
            ]),
        ]);

        return Inertia::render('Services', [
            'categories' => $categories,
        ]);
    }
}
