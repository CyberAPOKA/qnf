<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'amount',
        'pix_payload',
        'external_id',
        'qr_code_base64',
        'paid_at',
        'method',
        'penalty_rounds',
    ];

    public const METHOD_SYSTEM = 'system';
    public const METHOD_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'amount' => 'integer',
            'penalty_rounds' => 'integer',
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

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
