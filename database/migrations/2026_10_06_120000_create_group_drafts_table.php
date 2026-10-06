<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->foreignId('published_group_id')->nullable()->unique()->constrained('groups')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_id', 'status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_drafts');
    }
};
