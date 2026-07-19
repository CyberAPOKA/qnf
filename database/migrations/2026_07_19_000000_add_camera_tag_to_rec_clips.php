<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_clips', function (Blueprint $table) {
            $table->string('camera_tag', 8)->nullable()->after('recorder_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_clips', function (Blueprint $table) {
            $table->dropColumn('camera_tag');
        });
    }
};
