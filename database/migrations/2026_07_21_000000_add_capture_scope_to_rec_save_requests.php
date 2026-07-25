<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_save_requests', function (Blueprint $table) {
            $table->string('capture_scope', 10)->default('all')->after('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('rec_save_requests', function (Blueprint $table) {
            $table->dropColumn('capture_scope');
        });
    }
};
