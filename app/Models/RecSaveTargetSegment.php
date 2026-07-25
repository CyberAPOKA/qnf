<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecSaveTargetSegment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rec_save_target_id',
        'rec_segment_id',
        'order',
        'overlap_from_ms',
        'overlap_until_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
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
