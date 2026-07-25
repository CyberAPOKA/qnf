<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecOperationalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'game_id',
        'recorder_session_id',
        'rec_save_request_id',
        'rec_save_target_id',
        'rec_segment_id',
        'level',
        'event_type',
        'message',
        'context_json',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function recorderSession(): BelongsTo
    {
        return $this->belongsTo(RecRecorderSession::class, 'recorder_session_id');
    }

    public function saveRequest(): BelongsTo
    {
        return $this->belongsTo(RecSaveRequest::class, 'rec_save_request_id');
    }

    public function saveTarget(): BelongsTo
    {
        return $this->belongsTo(RecSaveTarget::class, 'rec_save_target_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(RecSegment::class, 'rec_segment_id');
    }
}
