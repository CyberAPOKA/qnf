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
     * @return array{ffmpeg: bool, v2_enabled: bool, continuous_segments_enabled: bool, storage_disk: string, processing_queue: string}
     */
    public function snapshot(): array
    {
        return [
            'ffmpeg' => $this->ffmpegAvailable(),
            'v2_enabled' => (bool) config('rec.v2_enabled'),
            'continuous_segments_enabled' => (bool) config('rec.continuous_segments_enabled'),
            'storage_disk' => (string) config('rec.storage_disk'),
            'processing_queue' => (string) config('rec.processing_queue'),
        ];
    }
}
