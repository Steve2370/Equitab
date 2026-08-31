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
        Schema::create('oauth_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->string('avatar')->nullable();
            $table->timestamps();

            // A given provider account can only ever be linked to one user.
            $table->unique(['provider', 'provider_id']);
            // A user can only link a given provider once (e.g. one Google account).
            $table->unique(['user_id', 'provider']);
        });

        // Migrate any google_id already set (e.g. from local testing) into the
        // new generic table before dropping the column, so no link is lost.
        DB::table('users')
            ->whereNotNull('google_id')
            ->select('id', 'google_id')
            ->orderBy('id')
            ->each(function (object $user) {
                DB::table('oauth_providers')->insert([
                    'user_id' => $user->id,
                    'provider' => 'google',
                    'provider_id' => $user->google_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
        });

        DB::table('oauth_providers')
            ->where('provider', 'google')
            ->select('user_id', 'provider_id')
            ->orderBy('user_id')
            ->each(function (object $link) {
                DB::table('users')->where('id', $link->user_id)->update([
                    'google_id' => $link->provider_id,
                ]);
            });

        Schema::dropIfExists('oauth_providers');
    }
};
