<?php

namespace App\Models;

use App\Enums\RecClipStatus;
use App\Support\PublicStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecClip extends Model
{
    protected $fillable = [
        'rec_save_request_id',
        'rec_save_target_id',
        'game_id',
        'user_id',
        'recorder_id',
        'camera_tag',
        'file_path',
        'raw_file_path',
        'preview_file_path',
        'final_file_path',
        'storage_disk',
        'status',
        'duration_seconds',
        'duration_ms',
        'bytes',
        'checksum_sha256',
        'mime_type',
        'container',
        'video_codec',
        'audio_codec',
        'processing_attempts',
        'processing_started_at',
        'processing_finished_at',
        'failure_code',
        'failure_message',
    ];

    protected $appends = [
        'url',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecClipStatus::class,
            'processing_started_at' => 'datetime',
            'processing_finished_at' => 'datetime',
        ];
    }

    public function saveRequest(): BelongsTo
    {
        return $this->belongsTo(RecSaveRequest::class, 'rec_save_request_id');
    }

    public function saveTarget(): BelongsTo
    {
        return $this->belongsTo(RecSaveTarget::class, 'rec_save_target_id');
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
        $path = $this->final_file_path
            ?? $this->preview_file_path
            ?? $this->file_path
            ?? $this->raw_file_path;

        return PublicStorage::url($path);
    }
}
