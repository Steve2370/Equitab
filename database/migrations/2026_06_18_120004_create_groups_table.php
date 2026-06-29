<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('subscription_id')->constrained();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('max_members');
            $table->integer('current_members')->default(1);
            $table->bigInteger('price_per_member');
            $table->enum('split_type', [
                'equal',
                'custom',
                'usage_based'
            ])->default('equal');
            $table->enum('status', [
                'open',
                'full',
                'closed',
                'suspended'
            ])->default('open');
            $table->enum('visibility', ['public', 'private', 'invite_only'])->default('public');
            $table->date('renewal_date');
            $table->boolean('auto_renew')->default(true);
            $table->json('settings')->nullable(); 
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
