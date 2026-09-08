<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSchedule extends Model
{
    protected $fillable = [
        'round',
        'creates_at',
        'opens_at',
        'starts_at',
        'game_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'creates_at' => 'datetime',
            'opens_at' => 'datetime',
            'starts_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->game_id === null;
    }
}
