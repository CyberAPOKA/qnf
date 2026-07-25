<?php

namespace App\Services\Rec;

class RecStorageService
{
    public function disk(): string
    {
        return (string) config('rec.storage_disk', 'public');
    }

    public function tempDisk(): string
    {
        return (string) config('rec.temp_storage_disk', 'local');
    }

    public function environment(): string
    {
        return (string) config('app.env', 'local');
    }

    public function segmentPath(int $gameId, string $sessionUuid, int $sequence, string $segmentUuid, string $ext = 'webm'): string
    {
        return sprintf(
            'rec/%s/games/%d/sessions/%s/segments/%d-%s.%s',
            $this->environment(),
            $gameId,
            $sessionUuid,
            $sequence,
            $segmentUuid,
            $ext,
        );
    }

    public function rawPath(int $gameId, string $saveUuid, string $cameraTag, string $filename): string
    {
        return $this->saveArtifactPath($gameId, $saveUuid, $cameraTag, 'raw', $filename);
    }

    public function previewPath(int $gameId, string $saveUuid, string $cameraTag, string $filename): string
    {
        return $this->saveArtifactPath($gameId, $saveUuid, $cameraTag, 'preview', $filename);
    }

    public function finalPath(int $gameId, string $saveUuid, string $cameraTag, string $filename): string
    {
        return $this->saveArtifactPath($gameId, $saveUuid, $cameraTag, 'final', $filename);
    }

    public function tmpPath(int $gameId, string $filename): string
    {
        return sprintf(
            'rec/%s/games/%d/tmp/%s',
            $this->environment(),
            $gameId,
            $filename,
        );
    }

    private function saveArtifactPath(
        int $gameId,
        string $saveUuid,
        string $cameraTag,
        string $kind,
        string $filename,
    ): string {
        return sprintf(
            'rec/%s/games/%d/saves/%s/%s/%s/%s',
            $this->environment(),
            $gameId,
            $saveUuid,
            $cameraTag,
            $kind,
            $filename,
        );
    }
}
