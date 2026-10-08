<?php

use App\Features\Group\Services\LegacyGroupCurrency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Atomic even when exercised directly by the isolated migration tests.
        $upgrade = function (): void {
            DB::transaction(function (): void {
                Schema::table('groups', function (Blueprint $table): void {
                    $table->string('currency', 3)->nullable();
                });
                $resolver = new LegacyGroupCurrency;
                DB::table('groups')->select(['id', 'subscription_id'])->orderBy('id')->chunkById(100, function ($groups) use ($resolver): void {
                    foreach ($groups as $group) {
                        $currency = $resolver->resolve((int) $group->id, (int) $group->subscription_id);
                        DB::table('groups')->where('id', $group->id)->update(['currency' => $currency]);
                    }
                });
                Schema::table('groups', function (Blueprint $table): void {
                    $table->string('currency', 3)->nullable(false)->change();
                });
                if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('La migration de devise ne peut pas modifier les références financières.');
                }
            });
        };

        if (DB::getDriverName() === 'sqlite') {
            // Laravel rebuilds a SQLite table for NOT NULL. Its PRAGMA must be
            // set before the transaction, otherwise DROP can cascade or fail.
            // Recheck every foreign key before committing, then restore enforcement.
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('Exécuter cette migration SQLite en dehors d’une transaction englobante.');
            }
            Schema::withoutForeignKeyConstraints($upgrade);
        } else {
            $upgrade();
        }
    }

    public function down(): void
    {
        // The old application has CAD-only financial consumers. Never erase a
        // new EUR contract, or revert a snapshot to a different catalogue value.
        $unsafe = DB::table('groups')->leftJoin('subscriptions', 'subscriptions.id', '=', 'groups.subscription_id')
            ->where(function ($query): void {
                $query->where('groups.currency', '!=', 'CAD')
                    ->orWhereNull('subscriptions.currency')
                    ->orWhereColumn('groups.currency', '!=', 'subscriptions.currency');
            })->exists();
        if ($unsafe) {
            throw new RuntimeException('Retour arrière refusé : conserver les devises des groupes et désactiver seulement les nouvelles souscriptions EUR.');
        }
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
