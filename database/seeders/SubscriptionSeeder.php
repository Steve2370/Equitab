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
        $securite = SubscriptionCategory::firstOrCreate(['name' => 'Sécurité']);
        $productivite = SubscriptionCategory::firstOrCreate(['name' => 'Productivité']);
        $education = SubscriptionCategory::firstOrCreate(['name' => 'Éducation']);
        $lecture = SubscriptionCategory::firstOrCreate(['name' => 'Lecture']);

        $services = [
            [$streaming->id, 'Netflix', 1999, 4],
            [$streaming->id, 'Disney+', 1399, 4],
            [$streaming->id, 'YouTube Premium', 1799, 6],
            [$streaming->id, 'Crave', 2099, 4],
            [$streaming->id, 'Crunchyroll', 799, 4],
            [$streaming->id, 'Paramount+', 1099, 4],
            [$streaming->id, 'Canal+', 2299, 4],
            [$streaming->id, 'Amazon Prime', 999, 6],
            [$musique->id, 'Spotify', 1599, 6],
            [$musique->id, 'Apple Music', 1499, 6],
            [$musique->id, 'Deezer', 1299, 6],
            [$musique->id, 'Tidal', 1999, 6],
            [$jeux->id, 'Xbox Game Pass', 1699, 4],
            [$jeux->id, 'Nintendo', 2499, 4],
            [$securite->id, 'NordVPN', 1399, 6],
            [$securite->id, 'CyberGhost',      999, 7],
            [$productivite->id, 'Envato', 5499, 4],
            [$productivite->id, 'Google One', 299, 5],
            [$productivite->id, 'Microsoft 365', 1099, 6],
            [$productivite->id, 'Adobe Creative Cloud', 6999, 4],
            [$education->id, 'Duolingo', 899, 6],
            [$lecture->id, 'Readly', 999, 5],
        ];

        foreach ($services as [$catId, $name, $price, $max]) {
            Subscription::firstOrCreate(
                ['name' => $name],
                [
                    'category_id' => $catId,
                    'slug' => str($name)->slug(),
                    'max_members' => $max,
                    'monthly_price' => $price,
                    'currency' => 'CAD',
                    'billing_cycle' => 'monthly',
                    'is_active' => true,
                    'tier' => 'standard',
                ]
            );
        }
    }
}
