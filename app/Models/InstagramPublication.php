<?php

namespace App\Models;

use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Enums\InstagramTriggerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InstagramPublication extends Model
{
    protected $fillable = [
        'uuid',
        'instagram_account_id',
        'trigger_type',
        'trigger_id',
        'trigger_version',
        'publication_type',
        'status',
        'idempotency_key',
        'payload',
        'metadata',
        'instagram_container_id',
        'instagram_media_id',
        'permalink',
        'attempts',
        'last_error_code',
        'last_error_message',
        'queued_at',
        'processing_started_at',
        'published_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => InstagramTriggerType::class,
            'publication_type' => InstagramPublicationType::class,
            'status' => InstagramPublicationStatus::class,
            'payload' => 'array',
            'metadata' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $publication): void {
            if (! $publication->uuid) {
                $publication->uuid = (string) Str::uuid();
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'instagram_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InstagramPublicationItem::class)->orderBy('position');
    }

    public function markFailed(string $message, ?string $code = null): void
    {
        $this->update([
            'status' => InstagramPublicationStatus::Failed,
            'last_error_message' => $message,
            'last_error_code' => $code,
            'failed_at' => now(),
        ]);
    }

    public function mergeMetadata(array $extra): void
    {
        $this->update([
            'metadata' => array_merge($this->metadata ?? [], $extra),
        ]);
    }
}
