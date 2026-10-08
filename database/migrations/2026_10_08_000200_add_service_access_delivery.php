<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->string('access_mode', 20)->default('credentials');
        });
        Schema::table('group_members', function (Blueprint $table): void {
            $table->text('service_invitation_url')->nullable();
            $table->string('service_invitation_channel', 20)->nullable();
            $table->text('service_invitation_email')->nullable();
            $table->timestamp('service_invitation_provided_at')->nullable();
            $table->timestamp('service_access_revoked_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table): void {
            // Existing scheduled checks retain their original contract.
            $table->unsignedSmallInteger('access_check_version')->default(1);
        });
    }

    public function down(): void
    {
        if (DB::table('groups')->where('access_mode', 'invitation')->exists()
            || DB::table('group_members')->whereNotNull('service_invitation_provided_at')->exists()
            || DB::table('payments')->where('access_check_version', '>', 1)->exists()) {
            throw new RuntimeException('Des accès ou paiements utilisent ce schéma. Conservez les colonnes lors du retour arrière applicatif.');
        }
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('access_check_version'));
        Schema::table('group_members', fn (Blueprint $table) => $table->dropColumn([
            'service_invitation_url', 'service_invitation_provided_at', 'service_access_revoked_at',
            'service_invitation_channel', 'service_invitation_email',
        ]));
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn('access_mode'));
    }
};
