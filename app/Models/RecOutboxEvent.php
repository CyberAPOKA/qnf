<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecOutboxEvent extends Model
{
    protected $fillable = [
        'uuid',
        'game_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'available_at',
        'published_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
