<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('music_youtube_id')->nullable()->after('whatsapp_notifications');
            $table->string('music_title')->nullable()->after('music_youtube_id');
            $table->string('music_thumbnail_url')->nullable()->after('music_title');
            $table->unsignedInteger('music_start_seconds')->default(0)->after('music_thumbnail_url');
            $table->unsignedInteger('music_end_seconds')->default(30)->after('music_start_seconds');
            $table->unsignedTinyInteger('music_duration_seconds')->default(30)->after('music_end_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'music_youtube_id',
                'music_title',
                'music_thumbnail_url',
                'music_start_seconds',
                'music_end_seconds',
                'music_duration_seconds',
            ]);
        });
    }
};
