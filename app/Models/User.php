<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Position;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use App\Support\PublicStorage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use SoftDeletes;

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
        'music_youtube_id',
        'music_title',
        'music_channel',
        'music_thumbnail_url',
        'music_start_seconds',
        'music_end_seconds',
        'music_duration_seconds',
        'music_watch_url',
        'music_source',
        'music_file_path',
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
        'music_file_url',
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
            'music_start_seconds' => 'integer',
            'music_end_seconds' => 'integer',
            'music_duration_seconds' => 'integer',
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

    public function playerCards(): HasMany
    {
        return $this->hasMany(PlayerCard::class);
    }

    public function playerCardCycles(): HasMany
    {
        return $this->hasMany(PlayerCardCycle::class);
    }

    public function getPhotoFrontUrlAttribute(): ?string
    {
        return PublicStorage::url($this->photo_front);
    }

    public function getPhotoSideUrlAttribute(): ?string
    {
        return PublicStorage::url($this->photo_side);
    }

    public function getMusicFileUrlAttribute(): ?string
    {
        return PublicStorage::browserUrl($this->music_file_path);
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
