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
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id')->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->timestamp('joined_at');
            $table->timestamp('waitlist_at')->nullable();
            $table->unsignedTinyInteger('points')->default(0);
            $table->boolean('dropped_out')->default(false);

            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
            $table->index('game_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
