<?php

namespace App\Models;

use App\Enums\TeamColor;
use App\Support\PublicStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameWeekTeamMusic extends Model
{

    protected $table = 'game_week_team_musics';

    protected $fillable = [
        'game_id',
        'team_color',
        'captain_user_id',
        'sort_order',
        'music_source',
        'music_youtube_id',
        'music_title',
        'music_channel',
        'music_thumbnail_url',
        'music_start_seconds',
        'music_end_seconds',
        'music_duration_seconds',
        'music_watch_url',
        'music_file_path',
    ];

    protected function casts(): array
    {
        return [
            'team_color' => TeamColor::class,
            'music_start_seconds' => 'integer',
            'music_end_seconds' => 'integer',
            'music_duration_seconds' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_user_id');
    }

    public function toPlaybackArray(): array
    {
        if ($this->music_source === 'youtube' && $this->music_youtube_id) {
            return [
                'source' => 'youtube',
                'youtube_id' => $this->music_youtube_id,
                'title' => $this->music_title,
                'start_seconds' => $this->music_start_seconds,
                'end_seconds' => $this->music_end_seconds,
                'duration_seconds' => $this->music_duration_seconds,
            ];
        }

        if ($this->music_source === 'mp3' && $this->music_file_path) {
            return [
                'source' => 'mp3',
                'file_url' => PublicStorage::browserUrl($this->music_file_path),
                'title' => $this->music_title,
                'start_seconds' => $this->music_start_seconds,
                'end_seconds' => $this->music_end_seconds,
                'duration_seconds' => $this->music_duration_seconds,
            ];
        }

        return ['source' => 'default'];
    }
}
