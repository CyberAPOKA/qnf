<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_week_team_musics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('team_color', 20);
            $table->foreignId('captain_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->string('music_source', 20)->default('default');
            $table->string('music_youtube_id')->nullable();
            $table->string('music_title')->nullable();
            $table->string('music_channel')->nullable();
            $table->string('music_thumbnail_url')->nullable();
            $table->unsignedInteger('music_start_seconds')->default(0);
            $table->unsignedInteger('music_end_seconds')->default(30);
            $table->unsignedTinyInteger('music_duration_seconds')->default(30);
            $table->string('music_watch_url')->nullable();
            $table->string('music_file_path')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'team_color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_week_team_musics');
    }
};
