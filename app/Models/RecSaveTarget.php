<?php

namespace App\Models;

use App\Enums\RecSaveTargetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecSaveTarget extends Model
{
    protected $fillable = [
        'rec_save_request_id',
        'recorder_session_id',
        'camera_tag',
        'status',
        'expected_from',
        'expected_until',
        'acknowledged_at',
        'segments_expected',
        'segments_received',
        'segments_missing',
        'raw_ready_at',
        'preview_ready_at',
        'final_ready_at',
        'last_error_at',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecSaveTargetStatus::class,
            'expected_from' => 'datetime',
            'expected_until' => 'datetime',
            'acknowledged_at' => 'datetime',
            'raw_ready_at' => 'datetime',
            'preview_ready_at' => 'datetime',
            'final_ready_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function saveRequest(): BelongsTo
    {
        return $this->belongsTo(RecSaveRequest::class, 'rec_save_request_id');
    }

    public function recorderSession(): BelongsTo
    {
        return $this->belongsTo(RecRecorderSession::class, 'recorder_session_id');
    }

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(
            RecSegment::class,
            'rec_save_target_segments',
            'rec_save_target_id',
            'rec_segment_id',
        )->withPivot(['order', 'overlap_from_ms', 'overlap_until_ms', 'created_at']);
    }

    public function clip(): HasOne
    {
        return $this->hasOne(RecClip::class, 'rec_save_target_id');
    }
}
