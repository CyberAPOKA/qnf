<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Position;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'role',
        'position',
        'guest',
        'photo_front',
        'photo_side',
        'whatsapp_notifications',
        'suspended_until_round',
        'ability',
        'active',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'position' => Position::class,
            'guest' => 'boolean',
            'whatsapp_notifications' => 'boolean',
            'suspended_until_round' => 'integer',
            'ability' => 'integer',
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_players')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    public function captainedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'captain_user_id');
    }

    public function getPhotoFrontUrlAttribute(): ?string
    {
        return $this->photo_front ? Storage::url($this->photo_front) : null;
    }

    public function getPhotoSideUrlAttribute(): ?string
    {
        return $this->photo_side ? Storage::url($this->photo_side) : null;
    }

    public function getInitialAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }

    public function isSuspended(int $currentRound): bool
    {
        if ($this->suspended_until_round === null) {
            return false;
        }

        // 0 = permanent
        if ($this->suspended_until_round === 0) {
            return true;
        }

        return $currentRound < $this->suspended_until_round;
    }
}
