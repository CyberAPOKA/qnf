<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_publication_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_publication_id')->constrained('instagram_publications')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('media_type');
            $table->string('local_path')->nullable();
            $table->string('public_url')->nullable();
            $table->string('instagram_container_id')->nullable();
            $table->string('status')->default('pending');
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['instagram_publication_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_publication_items');
    }
};
