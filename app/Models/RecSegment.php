<?php

namespace App\Models;

use App\Enums\RecSegmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RecSegment extends Model
{
    protected $fillable = [
        'uuid',
        'recorder_session_id',
        'game_id',
        'sequence',
        'idempotency_key',
        'client_started_at',
        'client_ended_at',
        'estimated_server_started_at',
        'estimated_server_ended_at',
        'duration_ms',
        'file_path',
        'storage_disk',
        'mime_type',
        'container',
        'video_codec',
        'audio_codec',
        'bytes',
        'checksum_sha256',
        'status',
        'upload_attempts',
        'received_at',
        'verified_at',
        'pinned_until',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecSegmentStatus::class,
            'client_started_at' => 'datetime',
            'client_ended_at' => 'datetime',
            'estimated_server_started_at' => 'datetime',
            'estimated_server_ended_at' => 'datetime',
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
            'pinned_until' => 'datetime',
        ];
    }

    public function recorderSession(): BelongsTo
    {
        return $this->belongsTo(RecRecorderSession::class, 'recorder_session_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function saveTargets(): BelongsToMany
    {
        return $this->belongsToMany(
            RecSaveTarget::class,
            'rec_save_target_segments',
            'rec_segment_id',
            'rec_save_target_id',
        )->withPivot(['order', 'overlap_from_ms', 'overlap_until_ms', 'created_at']);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
