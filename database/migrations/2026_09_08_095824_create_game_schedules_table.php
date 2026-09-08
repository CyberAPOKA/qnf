<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('round');
            $table->dateTime('creates_at');
            $table->dateTime('opens_at');
            $table->dateTime('starts_at');
            $table->foreignId('game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_schedules');
    }
};
