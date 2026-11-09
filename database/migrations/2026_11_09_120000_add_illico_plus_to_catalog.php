<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // illico+ : 2 visionnements simultanés, donc 2 places (propriétaire compris).
    // Prix de référence mensuel en CAD, modifiable ensuite par l'opérateur.
    private const OFFER = [
        'name' => 'illico+', 'slug' => 'illico', 'category' => 'Streaming',
        'monthly_price' => 1500, 'max_members' => 2,
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE subscriptions, subscription_categories IN SHARE ROW EXCLUSIVE MODE');
            }

            $matches = DB::table('subscriptions')
                ->whereRaw('LOWER(TRIM(slug)) = ?', [self::OFFER['slug']])
                ->orWhereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(self::OFFER['name'])])->get();
            if ($matches->count() > 1 || ($matches->first() && ($matches->first()->slug !== self::OFFER['slug']
                || $matches->first()->name !== self::OFFER['name'] || $matches->first()->currency !== 'CAD'))) {
                throw new RuntimeException('Catalogue illico+ : identité ambiguë, aucune modification.');
            }
            if ($matches->first()) {
                // Déjà présent (seeder ou ajout manuel) : ne jamais écraser les réglages de l'opérateur.
                return;
            }

            $categories = DB::table('subscription_categories')
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(self::OFFER['category'])])->get();
            if ($categories->count() > 1) {
                throw new RuntimeException('Catalogue illico+ : catégorie Streaming ambiguë, aucune modification.');
            }
            if (! $categories->first()) {
                // Installation neuve sans catalogue : SubscriptionSeeder ajoute illico+.
                return;
            }
            $categoryId = $categories->first()->id;

            DB::table('subscriptions')->insert([
                'category_id' => $categoryId, 'name' => self::OFFER['name'], 'slug' => self::OFFER['slug'],
                'max_members' => self::OFFER['max_members'], 'monthly_price' => self::OFFER['monthly_price'],
                'currency' => 'CAD', 'billing_cycle' => 'monthly', 'tier' => 'standard',
                'access_mode' => 'credentials', 'is_active' => true, 'is_verified' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $offer = DB::table('subscriptions')->where('slug', self::OFFER['slug'])->where('name', self::OFFER['name'])->first();
            if (! $offer) {
                return;
            }
            $referenced = DB::table('groups')->where('subscription_id', $offer->id)->exists()
                || DB::table('group_drafts')->where('data->subscription_id', $offer->id)
                    ->orWhere('data->subscription_id', (string) $offer->id)->exists();
            if ($referenced) {
                throw new RuntimeException('Retour arrière illico+ refusé : des groupes ou brouillons l\'utilisent.');
            }
            DB::table('subscriptions')->where('id', $offer->id)->delete();
        });
    }
};
