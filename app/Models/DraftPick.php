<?php

namespace App\Models;

use App\Enums\TeamColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftPick extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'round',
        'pick_in_round',
        'team_color',
        'picked_user_id',
        'picked_at',
    ];

    protected function casts(): array
    {
        return [
            'picked_at' => 'datetime',
            'team_color' => TeamColor::class,
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function pickedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_user_id');
    }
}
