<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Netflix', 'Spotify', 'Disney+', 'Crunchyroll', 'YouTube Premium',
        ]) . ' ' . fake()->unique()->numberBetween(1, 100000);

        return [
            'category_id' => SubscriptionCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'logo' => null,
            'website' => null,
            'max_members' => 6,
            'monthly_price' => 1999,
            'currency' => 'CAD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'is_verified' => true,
            'tier' => 'standard',
        ];
    }
}
