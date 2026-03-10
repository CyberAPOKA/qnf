<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'user_id',
        'joined_at',
        'waitlist_at',
        'points',
        'dropped_out',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'waitlist_at' => 'datetime',
            'dropped_out' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
