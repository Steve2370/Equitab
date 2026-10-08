<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE subscriptions IN SHARE ROW EXCLUSIVE MODE');
            }

            // Dated official sources and billing channels are recorded in README.
            // Keep this release snapshot independent from future catalogue updates.
            foreach ([
                ['slug' => 'dropbox-family', 'name' => 'Dropbox Family', 'price' => 2649, 'cycle' => 'monthly'],
                ['slug' => 'nordpass-family', 'name' => 'NordPass Family', 'price' => 749, 'cycle' => 'yearly'],
            ] as $reference) {
                $matches = DB::table('subscriptions')
                    ->whereRaw('LOWER(TRIM(slug)) = ?', [$reference['slug']])
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($reference['name'])])->get();
                $offer = $matches->first();
                if ($matches->count() > 1 || ($offer && ($offer->slug !== $reference['slug']
                    || $offer->name !== $reference['name'] || $offer->currency !== 'CAD'
                    || $offer->access_mode !== 'invitation' || (int) $offer->max_members !== 6))) {
                    throw new RuntimeException("Tarif {$reference['slug']} : identité de catalogue ambiguë, aucune modification.");
                }

                // Only fill the missing catalogue reference. Never replace an operator's
                // existing price/cycle or touch groups, drafts, attempts, payments or Stripe.
                if ($offer && $offer->monthly_price === null && $offer->billing_cycle === null) {
                    DB::table('subscriptions')->where('id', $offer->id)->whereNull('monthly_price')
                        ->whereNull('billing_cycle')->update([
                            'monthly_price' => $reference['price'], 'billing_cycle' => $reference['cycle'], 'updated_at' => now(),
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retour arrière tarif refusé : ne pas effacer un prix renseigné par un opérateur.');
    }
};
