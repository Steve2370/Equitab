<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->alter(function (Blueprint $table): void {
            // No default/backfill: currency, locale and an old address are not proof of country.
            $table->string('country', 2)->nullable();
            if (DB::getDriverName() !== 'sqlite') {
                $table->string('province', 100)->nullable()->change();
                $table->string('postal_code', 20)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('country')
            ->orWhereRaw('length(province) > 2')->orWhereRaw('length(postal_code) > 10')->exists()) {
            throw new RuntimeException('Cannot remove owner countries or truncate addresses. Keep this additive migration.');
        }
        $this->alter(function (Blueprint $table): void {
            $table->dropColumn('country');
            if (DB::getDriverName() !== 'sqlite') {
                $table->string('province', 2)->nullable()->change();
                $table->string('postal_code', 10)->nullable()->change();
            }
        });
    }

    private function alter(Closure $definition): void
    {
        // SQLite does not enforce varchar lengths: do not rebuild users merely
        // to resize them. A rebuild can lose partial indexes or cascade children.
        DB::transaction(function () use ($definition): void {
            Schema::table('users', $definition);
            if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Owner country migration must preserve every existing reference.');
            }
        });
    }
};
