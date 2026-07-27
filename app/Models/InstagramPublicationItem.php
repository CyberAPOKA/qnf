<?php

namespace App\Models;

use App\Instagram\Enums\InstagramPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramPublicationItem extends Model
{
    protected $fillable = [
        'instagram_publication_id',
        'position',
        'media_type',
        'local_path',
        'public_url',
        'instagram_container_id',
        'status',
        'metadata',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => InstagramPublicationStatus::class,
            'metadata' => 'array',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(InstagramPublication::class, 'instagram_publication_id');
    }
}
