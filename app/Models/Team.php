<?php

namespace App\Models;

use App\Enums\TeamColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'color',
        'captain_user_id',
        'first_pick_user_id',
        'pick_order',
    ];

    protected function casts(): array
    {
        return [
            'color' => TeamColor::class,
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_user_id');
    }

    public function firstPick(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_pick_user_id');
    }
}
