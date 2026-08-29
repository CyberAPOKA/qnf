<?php

use App\Enums\DraftNarrationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_narrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('version')->default(1);
            $table->string('voice', 32);
            $table->text('text')->nullable();
            $table->string('path')->nullable();
            $table->string('status', 32)->default(DraftNarrationStatus::PENDING->value);
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'team_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_narrations');
    }
};
