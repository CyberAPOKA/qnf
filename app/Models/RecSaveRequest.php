<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecSaveRequest extends Model
{
    protected $fillable = [
        'game_id',
        'triggered_by',
        'uuid',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(RecClip::class);
    }
}
