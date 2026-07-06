<?php

namespace App\Models;

use App\Enums\GameStatus;
use App\Support\PublicStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'opens_at',
        'closes_at',
        'round',
        'status',
        'created_by',
        'week_team_images',
        'captains_image',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'status' => GameStatus::class,
            'week_team_images' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'game_players')
            ->withPivot(['joined_at', 'dropped_out', 'waitlist_at'])
            ->wherePivot('dropped_out', false)
            ->wherePivotNull('waitlist_at')
            ->withTimestamps();
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function draftPicks(): HasMany
    {
        return $this->hasMany(DraftPick::class);
    }

    public function weekTeamMusics(): HasMany
    {
        return $this->hasMany(GameWeekTeamMusic::class)->orderBy('sort_order');
    }

    public function getWeekTeamImageUrlsAttribute(): array
    {
        if (empty($this->week_team_images)) {
            return [];
        }

        return PublicStorage::urls($this->week_team_images);
    }

    public function getWeekTeamsAttribute(): array
    {
        if (empty($this->week_team_images)) {
            return [];
        }

        $musicsByColor = $this->relationLoaded('weekTeamMusics')
            ? $this->weekTeamMusics->keyBy(fn (GameWeekTeamMusic $music) => $music->team_color->value)
            : $this->weekTeamMusics()->get()->keyBy(fn (GameWeekTeamMusic $music) => $music->team_color->value);

        $teams = [];

        foreach ($this->week_team_images as $imagePath) {
            $color = null;

            if (preg_match('/team-(\w+)\.png$/', $imagePath, $matches)) {
                $color = $matches[1];
            }

            $music = $color ? $musicsByColor->get($color) : null;

            $teams[] = [
                'image' => PublicStorage::url($imagePath),
                'color' => $color,
                'music' => $music?->toPlaybackArray() ?? ['source' => 'default'],
            ];
        }

        return $teams;
    }

    public function getCaptainsImageUrlAttribute(): ?string
    {
        return PublicStorage::url($this->captains_image);
    }
}
