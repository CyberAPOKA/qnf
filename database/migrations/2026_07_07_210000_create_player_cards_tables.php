<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_card_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('infraction_round')->nullable();
            $table->unsignedInteger('display_until_round')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'display_until_round']);
        });

        Schema::create('player_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('player_card_cycles')->cascadeOnDelete();
            $table->string('type', 10);
            $table->unsignedInteger('round');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_cards');
        Schema::dropIfExists('player_card_cycles');
    }
};
