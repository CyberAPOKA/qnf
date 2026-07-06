<?php

namespace App\Services;

use App\Enums\TeamColor;
use App\Models\Game;
use App\Models\GameWeekTeamMusic;
use App\Models\User;
use App\Models\Team;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\Storage;

class WeekTeamMusicService
{
    /**
     * @param  TeamColor[]  $winnerColors
     */
    public function snapshotForGame(Game $game, array $winnerColors): void
    {
        $this->clearSnapshots($game);

        if (empty($winnerColors)) {
            return;
        }

        $round = $game->round ?? $game->id;
        $game->loadMissing(['teams.captain']);

        foreach ($winnerColors as $sortOrder => $color) {
            $team = $game->teams->firstWhere('color', $color);
            $captain = $team?->captain;

            GameWeekTeamMusic::create(
                $this->buildSnapshotData($game, $color, $captain, $sortOrder, $round)
            );
        }
    }

    public function userHasMusic(User $user): bool
    {
        if ($user->music_youtube_id) {
            return true;
        }

        return (bool) $user->music_file_path;
    }

    /**
     * @return 'created'|'updated'|'skipped'
     */
    public function upsertCaptainSnapshot(
        Game $game,
        TeamColor $color,
        User $captain,
        int $sortOrder,
        bool $force = false,
    ): string {
        $existing = GameWeekTeamMusic::query()
            ->where('game_id', $game->id)
            ->where('team_color', $color)
            ->first();

        if ($existing && ! $force) {
            return 'skipped';
        }

        if ($existing?->music_file_path) {
            Storage::disk('public')->delete($existing->music_file_path);
        }

        $round = $game->round ?? $game->id;
        $data = $this->buildSnapshotData($game, $color, $captain, $sortOrder, $round);

        if ($existing) {
            $existing->update($data);

            return 'updated';
        }

        GameWeekTeamMusic::create($data);

        return 'created';
    }

    public function resolveSortOrderForColor(Game $game, TeamColor $color): int
    {
        foreach ($game->week_team_images ?? [] as $index => $imagePath) {
            if (preg_match('/team-'.$color->value.'\.png$/', $imagePath)) {
                return $index;
            }
        }

        return 0;
    }

    public function isWinningTeamInGame(Team $team): bool
    {
        $images = $team->game?->week_team_images ?? [];

        return collect($images)->contains(
            fn (string $path) => str_contains($path, 'team-'.$team->color->value.'.png')
        );
    }

    public function clearSnapshots(Game $game): void
    {
        $game->loadMissing('weekTeamMusics');

        foreach ($game->weekTeamMusics as $music) {
            if ($music->music_file_path) {
                Storage::disk('public')->delete($music->music_file_path);
            }
        }

        $game->weekTeamMusics()->delete();
    }

    private function buildSnapshotData(Game $game, TeamColor $color, ?User $captain, int $sortOrder, int $round): array
    {
        $base = [
            'game_id' => $game->id,
            'team_color' => $color,
            'captain_user_id' => $captain?->id,
            'sort_order' => $sortOrder,
            'music_source' => 'default',
        ];

        if (! $captain) {
            return $base;
        }

        $source = $captain->music_source;

        if ($source === 'mp3' && $captain->music_file_path) {
            $extension = pathinfo($captain->music_file_path, PATHINFO_EXTENSION) ?: 'mp3';
            $destPath = "week_team_music/{$round}/team-{$color->value}.{$extension}";

            if ($this->copyMusicFile($captain->music_file_path, $destPath)) {
                return array_merge($base, [
                    'music_source' => 'mp3',
                    'music_title' => $captain->music_title,
                    'music_start_seconds' => $captain->music_start_seconds ?? 0,
                    'music_end_seconds' => $captain->music_end_seconds ?? 30,
                    'music_duration_seconds' => $captain->music_duration_seconds ?? 30,
                    'music_file_path' => $destPath,
                ]);
            }
        }

        if (($source === 'youtube' || $captain->music_youtube_id) && $captain->music_youtube_id) {
            return array_merge($base, [
                'music_source' => 'youtube',
                'music_youtube_id' => $captain->music_youtube_id,
                'music_title' => $captain->music_title,
                'music_channel' => $captain->music_channel,
                'music_thumbnail_url' => $captain->music_thumbnail_url,
                'music_start_seconds' => $captain->music_start_seconds ?? 0,
                'music_end_seconds' => $captain->music_end_seconds ?? 30,
                'music_duration_seconds' => $captain->music_duration_seconds ?? 30,
                'music_watch_url' => $captain->music_watch_url,
            ]);
        }

        return $base;
    }

    private function copyMusicFile(string $sourcePath, string $destPath): bool
    {
        $localSource = PublicStorage::localPath($sourcePath);

        if (! $localSource) {
            return false;
        }

        $disk = Storage::disk('public');
        $directory = dirname($destPath);

        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        return copy($localSource, storage_path('app/public/'.ltrim($destPath, '/')));
    }
}
