<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_publications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instagram_account_id')->nullable()->constrained('instagram_accounts')->nullOnDelete();
            $table->string('trigger_type');
            $table->unsignedBigInteger('trigger_id');
            $table->string('trigger_version');
            $table->string('publication_type');
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->unique();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->string('instagram_container_id')->nullable();
            $table->string('instagram_media_id')->nullable();
            $table->string('permalink')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['trigger_type', 'trigger_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_publications');
    }
};
