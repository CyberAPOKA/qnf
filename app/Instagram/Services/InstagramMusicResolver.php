<?php

namespace App\Instagram\Services;

use App\Enums\TeamColor;
use App\Models\Game;
use App\Models\GameWeekTeamMusic;
use App\Models\User;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\Log;

class InstagramMusicResolver
{
    /**
     * @return array{path: ?string, source: string, title: ?string}
     */
    public function resolveForCaptain(?User $captain): array
    {
        if ($captain) {
            $fromCaptain = $this->resolveFilePath(
                $captain->music_file_path,
                (string) ($captain->music_source ?? ''),
                $captain->music_title ? (string) $captain->music_title : null,
                'captain',
            );

            if ($fromCaptain !== null) {
                return $fromCaptain;
            }
        }

        return $this->fallbackDefaultOrNone('captain_youtube_or_missing');
    }

    /**
     * Prefer week-team music snapshot (MP3), then captain file, then default.
     *
     * @return array{path: ?string, source: string, title: ?string}
     */
    public function resolveForTeam(Game $game, TeamColor $color): array
    {
        $game->loadMissing(['weekTeamMusics', 'teams.captain']);

        $snapshot = $game->weekTeamMusics->firstWhere('team_color', $color);

        if ($snapshot instanceof GameWeekTeamMusic) {
            $fromSnapshot = $this->resolveFilePath(
                $snapshot->music_file_path,
                (string) ($snapshot->music_source ?? ''),
                $snapshot->music_title ? (string) $snapshot->music_title : null,
                'snapshot',
            );

            if ($fromSnapshot !== null) {
                return $fromSnapshot;
            }

            if (($snapshot->music_source ?? '') === 'youtube' || $snapshot->music_youtube_id) {
                Log::info('Instagram story audio: YouTube-only snapshot cannot be embedded', [
                    'game_id' => $game->id,
                    'team_color' => $color->value,
                    'youtube_id' => $snapshot->music_youtube_id,
                ]);
            }
        }

        $captain = $game->teams->firstWhere('color', $color)?->captain;
        $fromCaptain = $this->resolveForCaptain($captain);

        if ($fromCaptain['path'] !== null) {
            return $fromCaptain;
        }

        return $this->fallbackDefaultOrNone('team_no_mp3');
    }

    /**
     * @return array{path: string, source: string, title: ?string}|null
     */
    private function resolveFilePath(
        ?string $relativePath,
        string $source,
        ?string $title,
        string $resolvedSource,
    ): ?array {
        if ($source === 'youtube') {
            return null;
        }

        if (! is_string($relativePath) || trim($relativePath) === '') {
            return null;
        }

        $absolute = PublicStorage::localPath($relativePath);

        if (! $absolute || ! is_file($absolute)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        if (! in_array($extension, ['mp3', 'm4a', 'aac', 'wav', 'ogg'], true)) {
            return null;
        }

        return [
            'path' => $absolute,
            'source' => $resolvedSource,
            'title' => $title,
        ];
    }

    /**
     * @return array{path: ?string, source: string, title: ?string}
     */
    private function fallbackDefaultOrNone(string $reason): array
    {
        $default = $this->resolveDefaultAudio();

        if ($default !== null) {
            return $default;
        }

        Log::info('Instagram story audio fallback to none', ['reason' => $reason]);

        return [
            'path' => null,
            'source' => 'none',
            'title' => null,
        ];
    }

    /**
     * @return array{path: string, source: string, title: ?string}|null
     */
    private function resolveDefaultAudio(): ?array
    {
        $configured = config('instagram.default_story_audio_path');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = trim($configured);
        $absolute = $this->resolveConfiguredPath($configured);

        if (! $absolute || ! is_file($absolute)) {
            return null;
        }

        return [
            'path' => $absolute,
            'source' => 'default',
            'title' => null,
        ];
    }

    private function resolveConfiguredPath(string $configured): ?string
    {
        if ($this->isAbsolutePath($configured)) {
            return $configured;
        }

        $fromPublicStorage = PublicStorage::localPath($configured);

        if ($fromPublicStorage && is_file($fromPublicStorage)) {
            return $fromPublicStorage;
        }

        $storagePublic = storage_path('app/public/'.ltrim(str_replace('\\', '/', $configured), '/'));

        if (is_file($storagePublic)) {
            return $storagePublic;
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
