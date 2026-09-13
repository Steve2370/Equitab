<?php

namespace Database\Factories;

use App\Models\SubscriptionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionCategory>
 */
class SubscriptionCategoryFactory extends Factory
{
    protected $model = SubscriptionCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Streaming', 'Musique', 'Cloud', 'Gaming']),
            'icon' => null,
            'color' => null,
        ];
    }
}
