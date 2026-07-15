<?php

namespace App\Models;

use App\Enums\CardType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlayerCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'cycle_id',
        'type',
        'round',
    ];

    protected function casts(): array
    {
        return [
            'type' => CardType::class,
            'round' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PlayerCardCycle::class, 'cycle_id');
    }
}
