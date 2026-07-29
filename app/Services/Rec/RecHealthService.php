<?php

namespace App\Services\Rec;

use App\Services\RecClipNormalizeService;
use Illuminate\Support\Facades\Cache;

class RecHealthService
{
    public function __construct(
        private readonly RecClipNormalizeService $normalize,
    ) {}

    public function ffmpegAvailable(bool $force = false): bool
    {
        if ($force) {
            Cache::forget('rec:ffmpeg_available');
        }

        return Cache::remember('rec:ffmpeg_available', 60, fn () => $this->normalize->ffmpegAvailable(true));
    }

    /**
     * @return array{ffmpeg: bool, storage_disk: string, processing_queue: string}
     */
    public function snapshot(): array
    {
        return [
            'ffmpeg' => $this->ffmpegAvailable(),
            'storage_disk' => (string) config('rec.storage_disk'),
            'processing_queue' => (string) config('rec.processing_queue'),
        ];
    }
}
