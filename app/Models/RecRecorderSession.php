<?php

namespace App\Models;

use App\Enums\RecRecorderSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecRecorderSession extends Model
{
    protected $fillable = [
        'uuid',
        'game_id',
        'user_id',
        'camera_tag',
        'status',
        'session_token_hash',
        'started_at',
        'heartbeat_at',
        'lease_expires_at',
        'buffer_ready_at',
        'buffer_available_ms',
        'last_segment_sequence',
        'last_segment_received_at',
        'last_client_event_at',
        'estimated_clock_offset_ms',
        'estimated_rtt_ms',
        'mime_type',
        'container',
        'video_codec',
        'audio_codec',
        'width',
        'height',
        'fps',
        'has_audio',
        'user_agent',
        'device_fingerprint_hash',
        'failure_code',
        'failure_message',
        'stopped_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecRecorderSessionStatus::class,
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'buffer_ready_at' => 'datetime',
            'last_segment_received_at' => 'datetime',
            'last_client_event_at' => 'datetime',
            'stopped_at' => 'datetime',
            'has_audio' => 'boolean',
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

    public function segments(): HasMany
    {
        return $this->hasMany(RecSegment::class, 'recorder_session_id');
    }

    public function saveTargets(): HasMany
    {
        return $this->hasMany(RecSaveTarget::class, 'recorder_session_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
