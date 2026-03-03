<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\TeamColor;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('captain_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('first_pick_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('color', TeamColor::values());
            $table->unsignedTinyInteger('pick_order');
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'color']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
