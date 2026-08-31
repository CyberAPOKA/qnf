<?php

namespace App\Services\DraftNarration;

use Illuminate\Support\Facades\Storage;

class DraftNarrationStorage
{
    public function disk(): string
    {
        return (string) config('fish-audio.disk', 'local');
    }

    public function relativePath(int $gameId, int $teamId): string
    {
        return "drafts/{$gameId}/narrations/{$teamId}.mp3";
    }

    public function put(int $gameId, int $teamId, string $contents): string
    {
        $path = $this->relativePath($gameId, $teamId);

        Storage::disk($this->disk())->put($path, $contents);

        return $path;
    }

    public function exists(?string $path): bool
    {
        return is_string($path) && $path !== '' && Storage::disk($this->disk())->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk($this->disk())->path($path);
    }
}
