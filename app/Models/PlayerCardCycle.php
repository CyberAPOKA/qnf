<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlayerCardCycle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'infraction_round',
        'display_until_round',
    ];

    protected function casts(): array
    {
        return [
            'infraction_round' => 'integer',
            'display_until_round' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(PlayerCard::class, 'cycle_id');
    }
}
