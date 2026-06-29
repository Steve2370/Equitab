<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $streaming = SubscriptionCategory::firstOrCreate(['name' => 'Streaming']);
        $musique = SubscriptionCategory::firstOrCreate(['name' => 'Musique']);
        $jeux = SubscriptionCategory::firstOrCreate(['name' => 'Jeux']);

        $services = [
            // Streaming
            ['category' => $streaming->id, 'name' => 'Netflix',        'monthly_price' => 1999, 'max_members' => 4],
            ['category' => $streaming->id, 'name' => 'Disney+',        'monthly_price' => 1399, 'max_members' => 4],
            ['category' => $streaming->id, 'name' => 'YouTube Premium','monthly_price' => 1799, 'max_members' => 6],
            ['category' => $streaming->id, 'name' => 'Crave',          'monthly_price' => 2099, 'max_members' => 4],
            // Musique
            ['category' => $musique->id,   'name' => 'Spotify',        'monthly_price' => 1599, 'max_members' => 6],
            ['category' => $musique->id,   'name' => 'Apple Music',    'monthly_price' => 1499, 'max_members' => 6],
            ['category' => $musique->id,   'name' => 'Deezer',         'monthly_price' => 1299, 'max_members' => 6],
            // Jeux
            ['category' => $jeux->id,      'name' => 'Xbox Game Pass', 'monthly_price' => 1699, 'max_members' => 4],
        ];

        foreach ($services as $s) {
            Subscription::firstOrCreate(
                ['name' => $s['name']],
                [
                    'category_id' => $s['category'],
                    'slug' => str($s['name'])->slug(),
                    'max_members' => $s['max_members'],
                    'monthly_price' => $s['monthly_price'],
                    'currency' => 'CAD',
                    'billing_cycle' => 'monthly',
                    'is_active' => true,
                    'tier' => 'standard',
                ]
            );
        }
    }
}
