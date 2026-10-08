<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->alter(function (Blueprint $table) {
            $table->bigInteger('monthly_price')->nullable()->change();
            $this->billingCycleColumn($table)->nullable()->default(null)->change();
            $table->string('access_mode', 20)->default('credentials');
        });
    }

    public function down(): void
    {
        // Never invent a price/cycle or reinterpret invitation offers as shared credentials.
        if (DB::table('subscriptions')->whereNull('monthly_price')->orWhereNull('billing_cycle')
            ->orWhere('access_mode', '!=', 'credentials')->exists()) {
            throw new RuntimeException('Retour arrière refusé : le catalogue contient des données nécessitant le schéma invitation.');
        }

        $this->alter(function (Blueprint $table) {
            $table->bigInteger('monthly_price')->nullable(false)->change();
            $this->billingCycleColumn($table)->nullable(false)->default('monthly')->change();
            $table->dropColumn('access_mode');
        });
    }

    private function billingCycleColumn(Blueprint $table): ColumnDefinition
    {
        // PostgreSQL stores Laravel enums as varchar + an existing CHECK constraint.
        // Alter varchar nullability without emitting an invalid TYPE ... CHECK clause.
        return DB::getDriverName() === 'pgsql'
            ? $table->string('billing_cycle')
            : $table->enum('billing_cycle', ['monthly', 'quarterly', 'yearly']);
    }

    private function alter(Closure $definition): void
    {
        $change = function () use ($definition): void {
            DB::transaction(function () use ($definition): void {
                Schema::table('subscriptions', $definition);
                if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('La migration catalogue doit conserver toutes les références existantes.');
                }
            });
        };

        if (DB::getDriverName() === 'sqlite') {
            // Changing nullability rebuilds this parent table on SQLite. Its foreign-key
            // pragma must run outside a transaction to avoid dropping/cascading children.
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('Exécuter cette migration SQLite hors transaction englobante.');
            }
            Schema::withoutForeignKeyConstraints($change);
        } else {
            $change();
        }
    }
};
