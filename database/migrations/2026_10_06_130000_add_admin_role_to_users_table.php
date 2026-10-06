<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->unsignedBigInteger('auth_version')->default(0);
        });

        // Earlier SQLite table rebuilds (nullable OAuth password) lost the
        // partial index predicate. Restore the existing production rule,
        // without deleting historical accounts or changing PostgreSQL.
        $this->restoreSqliteEmailIndex();

        // One-time preservation of the verified historical administrator.
        // Future registrations never inherit a role from an email address.
        DB::table('users')
            ->where('email', 'briceyouatchui@gmail.com')
            ->whereNotNull('email_verified_at')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'banned')
            ->update(['is_admin' => true]);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Avoid an old SQLite rebuild recreating a full unique index while
            // retained, soft-deleted accounts share an address with a new user.
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'auth_version']);
        });

        $this->restoreSqliteEmailIndex();
    }

    private function restoreSqliteEmailIndex(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
        }
    }
};
