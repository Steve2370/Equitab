<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite also has an enum CHECK. Rebuild the column so local tests
        // and installations accept the same Stripe states as PostgreSQL.
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('group_members', fn (Blueprint $table) => $table->string('subscription_status')->nullable()->change());

            return;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE group_members DROP CONSTRAINT group_members_subscription_status_check');
        DB::statement('ALTER TABLE group_members ALTER COLUMN subscription_status TYPE varchar(255)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE group_members DROP CONSTRAINT IF EXISTS group_members_subscription_status_check');
        DB::statement("ALTER TABLE group_members ADD CONSTRAINT group_members_subscription_status_check
            CHECK (subscription_status IN ('active', 'past_due', 'canceled', 'unpaid'))");
    }
};
