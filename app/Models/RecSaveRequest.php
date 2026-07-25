<?php

namespace App\Models;

use App\Enums\RecSaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecSaveRequest extends Model
{
    protected $fillable = [
        'game_id',
        'triggered_by',
        'uuid',
        'capture_scope',
        'status',
        'triggered_at',
        'capture_from',
        'capture_until',
        'expected_count',
        'acknowledged_count',
        'received_count',
        'ready_count',
        'failed_count',
        'deadline_at',
        'processing_started_at',
        'completed_at',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecSaveRequestStatus::class,
            'triggered_at' => 'datetime',
            'capture_from' => 'datetime',
            'capture_until' => 'datetime',
            'deadline_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

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

    public function targets(): HasMany
    {
        return $this->hasMany(RecSaveTarget::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
