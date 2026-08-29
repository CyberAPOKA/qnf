<?php

namespace App\Models;

use App\Enums\DraftNarrationStatus;
use App\Enums\NarratorVoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftNarration extends Model
{
    protected $fillable = [
        'game_id',
        'team_id',
        'version',
        'voice',
        'text',
        'path',
        'status',
        'whatsapp_sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'voice' => NarratorVoice::class,
            'status' => DraftNarrationStatus::class,
            'whatsapp_sent_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function wasSent(): bool
    {
        return $this->whatsapp_sent_at !== null;
    }
}
