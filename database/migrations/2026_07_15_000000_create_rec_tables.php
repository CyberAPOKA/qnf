<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_save_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->constrained('users')->cascadeOnDelete();
            $table->string('uuid')->unique();
            $table->timestamps();
        });

        Schema::create('rec_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_save_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('recorder_id');
            $table->string('file_path');
            $table->unsignedSmallInteger('duration_seconds')->default(30);
            $table->timestamps();

            $table->index(['game_id', 'rec_save_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_clips');
        Schema::dropIfExists('rec_save_requests');
    }
};
