<?php

namespace App\Features\Subscription\Services;

use App\Models\Subscription;
use App\Models\SubscriptionCategory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Applies the operator's catalogue choice without rewriting existing financial contracts. */
final class InvitationServiceCatalogue
{
    private const OFFERS = [
        ['name' => 'Dropbox Family', 'slug' => 'dropbox-family', 'category' => 'Productivité',
            'logo' => '/Images/services/dropbox.svg', 'website' => 'https://www.dropbox.com/family',
            // Canadian App Store monthly reference; web checkout prices may differ.
            'monthly_price' => 2649, 'billing_cycle' => 'monthly'],
        ['name' => 'NordPass Family', 'slug' => 'nordpass-family', 'category' => 'Sécurité',
            'logo' => '/Images/services/nordpass.png', 'website' => 'https://nordpass.com/family-password-manager/',
            // CAD renewal: 8,988 cents/year / 12. See README for the dated official source.
            'monthly_price' => 749, 'billing_cycle' => 'yearly'],
    ];

    /** @return list<array{name: string, slug: string, action: string, id: ?int}> */
    public function prepare(bool $apply = false): array
    {
        return DB::transaction(function () use ($apply): array {
            // Names/categories are not unique in the legacy schema. Serialize the short
            // operator write on PostgreSQL, including against other catalogue writers.
            if ($apply && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE subscriptions, subscription_categories, groups, group_drafts IN SHARE ROW EXCLUSIVE MODE');
            }

            $plan = [];
            foreach (self::OFFERS as $offer) {
                $matches = Subscription::query()
                    ->whereRaw('LOWER(TRIM(slug)) = ?', [$offer['slug']])
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($offer['name'])])->get();
                $existing = $matches->first();
                if ($matches->count() > 1 || ($existing && ($existing->slug !== $offer['slug']
                    || $existing->name !== $offer['name'] || $existing->currency !== 'CAD'
                    || $existing->access_mode !== 'invitation'))) {
                    throw new RuntimeException("Collision de catalogue pour {$offer['slug']} : aucune offre modifiée.");
                }

                $categories = SubscriptionCategory::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($offer['category'])])->get();
                if ($categories->count() > 1 || ($categories->first() && $categories->first()->name !== $offer['category'])) {
                    throw new RuntimeException("Catégorie ambiguë pour {$offer['slug']} : aucune offre modifiée.");
                }

                $plan[] = ['offer' => $offer, 'existing' => $existing, 'category' => $categories->first()];
            }

            // Check retirement before writing anything, just like offer/category collisions.
            $retired = Subscription::query()
                ->whereRaw('LOWER(TRIM(slug)) = ?', ['bitwarden-families'])
                ->orWhereRaw('LOWER(TRIM(name)) = ?', ['bitwarden families'])->get();
            if ($retired->count() > 1 || ($retired->first() && ($retired->first()->slug !== 'bitwarden-families'
                || $retired->first()->name !== 'Bitwarden Families'))) {
                throw new RuntimeException('Collision de catalogue pour bitwarden-families : aucune offre modifiée.');
            }

            $result = [];
            foreach ($plan as $item) {
                $offer = $item['offer'];
                $subscription = $item['existing'];
                $action = $subscription?->is_active ? 'déjà actif'
                    : ($subscription ? ($apply ? 'activé' : 'à activer') : ($apply ? 'créé actif' : 'à créer actif'));
                if ($apply && ! $subscription) {
                    $category = $item['category'] ?? SubscriptionCategory::firstOrCreate(['name' => $offer['category']]);
                    $subscription = Subscription::create([
                        'category_id' => $category->id, 'name' => $offer['name'], 'slug' => $offer['slug'],
                        'logo' => $offer['logo'], 'website' => $offer['website'],
                        'max_members' => 6, 'monthly_price' => $offer['monthly_price'], 'currency' => 'CAD',
                        'billing_cycle' => $offer['billing_cycle'], 'tier' => 'famille',
                        'access_mode' => 'invitation', 'is_active' => true, 'is_verified' => false,
                    ]);
                } elseif ($apply && ! $subscription->is_active) {
                    $subscription->update(['is_active' => true]);
                }
                $result[] = ['name' => $offer['name'], 'slug' => $offer['slug'], 'action' => $action, 'id' => $subscription?->id];
            }

            if ($subscription = $retired->first()) {
                // Include archived groups and drafts; never cascade away members/payments.
                $referenced = DB::table('groups')->where('subscription_id', $subscription->id)->exists()
                    || DB::table('group_drafts')->where('data->subscription_id', $subscription->id)
                        ->orWhere('data->subscription_id', (string) $subscription->id)->exists();
                $action = $referenced ? 'retiré, historique conservé' : 'supprimé (sans référence)';
                if ($apply) {
                    if ($referenced && $subscription->is_active) {
                        $subscription->update(['is_active' => false]);
                    } elseif (! $referenced) {
                        $subscription->delete();
                    }
                }
                $result[] = ['name' => $subscription->name, 'slug' => $subscription->slug,
                    'action' => $apply ? $action : 'simulation : '.$action, 'id' => $subscription->id];
            }

            return $result;
        });
    }
}
