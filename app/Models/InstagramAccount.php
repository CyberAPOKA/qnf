<?php

namespace App\Models;

use App\Instagram\Enums\InstagramAccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramAccount extends Model
{
    protected $fillable = [
        'instagram_user_id',
        'username',
        'access_token',
        'access_token_expires_at',
        'last_refreshed_at',
        'status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'status' => InstagramAccountStatus::class,
        ];
    }

    protected $hidden = [
        'access_token',
    ];

    public function publications(): HasMany
    {
        return $this->hasMany(InstagramPublication::class);
    }

    public function isActive(): bool
    {
        return $this->status === InstagramAccountStatus::Active;
    }

    public function tokenExpiresSoon(int $days = 7): bool
    {
        if (! $this->access_token_expires_at) {
            return false;
        }

        return $this->access_token_expires_at->lte(now()->addDays($days));
    }

    public function isTokenExpired(): bool
    {
        return $this->access_token_expires_at !== null
            && $this->access_token_expires_at->lte(now());
    }
}
