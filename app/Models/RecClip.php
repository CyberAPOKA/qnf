<?php

namespace App\Models;

use App\Support\PublicStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecClip extends Model
{
    protected $fillable = [
        'rec_save_request_id',
        'game_id',
        'user_id',
        'recorder_id',
        'file_path',
        'duration_seconds',
    ];

    protected $appends = [
        'url',
    ];

    public function saveRequest(): BelongsTo
    {
        return $this->belongsTo(RecSaveRequest::class, 'rec_save_request_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): ?string
    {
        return PublicStorage::url($this->file_path);
    }
}
