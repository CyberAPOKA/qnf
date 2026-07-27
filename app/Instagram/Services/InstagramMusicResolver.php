<?php

namespace App\Instagram\Services;

use App\Models\User;
use App\Support\PublicStorage;

class InstagramMusicResolver
{
    /**
     * @return array{path: ?string, source: string, title: ?string}
     */
    public function resolveForCaptain(?User $captain): array
    {
        if ($captain) {
            $fromCaptain = $this->resolveCaptainFile($captain);

            if ($fromCaptain !== null) {
                return $fromCaptain;
            }
        }

        $default = $this->resolveDefaultAudio();

        if ($default !== null) {
            return $default;
        }

        return [
            'path' => null,
            'source' => 'none',
            'title' => null,
        ];
    }

    /**
     * @return array{path: string, source: string, title: ?string}|null
     */
    private function resolveCaptainFile(User $captain): ?array
    {
        $source = (string) ($captain->music_source ?? '');

        if ($source === 'youtube' || (! empty($captain->music_youtube_id) && empty($captain->music_file_path))) {
            return null;
        }

        if (empty($captain->music_file_path)) {
            return null;
        }

        $absolute = PublicStorage::localPath($captain->music_file_path);

        if (! $absolute || ! is_file($absolute)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        if (! in_array($extension, ['mp3', 'm4a', 'aac', 'wav', 'ogg'], true)) {
            return null;
        }

        return [
            'path' => $absolute,
            'source' => 'captain',
            'title' => $captain->music_title ? (string) $captain->music_title : null,
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
