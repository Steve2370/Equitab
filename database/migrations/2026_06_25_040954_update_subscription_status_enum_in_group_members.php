<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // This constraint/type change is PostgreSQL-specific syntax. On
        // other drivers (e.g. SQLite in tests) there's no named CHECK
        // constraint to drop, and SQLite columns are dynamically typed
        // anyway, so there's nothing to do there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE group_members DROP CONSTRAINT group_members_subscription_status_check");
        DB::statement("ALTER TABLE group_members ALTER COLUMN subscription_status TYPE varchar(255)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE group_members DROP CONSTRAINT IF EXISTS group_members_subscription_status_check");
        DB::statement("ALTER TABLE group_members ADD CONSTRAINT group_members_subscription_status_check
            CHECK (subscription_status IN ('active', 'past_due', 'canceled', 'unpaid'))");
    }
};
