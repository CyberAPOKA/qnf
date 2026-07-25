<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class RecClipNormalizeService
{
    /**
     * Normalize a browser MediaRecorder WebM into a clean clip of the last N seconds.
     * Fixes "dead time" caused by absolute WebM cluster timestamps.
     */
    public function normalize(string $relativePath, int $keepSeconds = 30): ?array
    {
        if (! $this->ffmpegAvailable()) {
            Log::warning('REC normalize skipped: ffmpeg not available', [
                'path' => $relativePath,
            ]);

            return null;
        }

        $disk = Storage::disk('public');
        $absolute = $disk->path($relativePath);

        if (! is_file($absolute)) {
            Log::warning('REC normalize skipped: file missing', ['path' => $relativePath]);

            return null;
        }

        $dir = dirname($absolute);
        $tmpOut = $dir.DIRECTORY_SEPARATOR.'tmp_out_'.uniqid('', true).'.webm';

        try {
            $cut = $this->runFfmpeg([
                '-sseof', '-'.$keepSeconds,
                '-i', $absolute,
                ...$this->webmEncodeArgs(),
                $tmpOut,
            ], 90);

            if (! $cut->successful() || ! is_file($tmpOut) || filesize($tmpOut) < 1) {
                Log::warning('REC normalize ffmpeg failed', [
                    'path' => $relativePath,
                    'error' => $cut->errorOutput(),
                ]);

                return null;
            }

            $duration = $this->probeDurationSeconds($tmpOut);

            if ($duration === null) {
                Log::warning('REC normalize probe failed before replace', ['path' => $relativePath]);

                return null;
            }

            if (! @rename($tmpOut, $absolute)) {
                if (! @copy($tmpOut, $absolute)) {
                    return null;
                }
                @unlink($tmpOut);
            }

            Log::info('REC normalize ok', [
                'path' => $relativePath,
                'duration' => $duration,
                'bytes' => @filesize($absolute),
            ]);

            return [
                'duration_seconds' => (int) max(1, round($duration)),
                'bytes' => (int) (@filesize($absolute) ?: 0),
            ];
        } finally {
            if (is_file($tmpOut)) {
                @unlink($tmpOut);
            }
        }
    }

    /**
     * @param  list<string>  $segmentAbsolutePaths
     * @return array{duration_seconds: int, bytes: int}|null
     */
    public function buildPreview(array $segmentAbsolutePaths, string $outputAbsolute): ?array
    {
        return $this->buildFromSegments(
            $segmentAbsolutePaths,
            $outputAbsolute,
            [
                '-vf', 'scale=-2:'.(int) config('rec.preview_height', 480),
                '-b:v', (string) config('rec.preview_video_bitrate', '700k'),
            ],
        );
    }

    /**
     * @param  list<string>  $segmentAbsolutePaths
     * @return array{duration_seconds: int, bytes: int}|null
     */
    public function buildFinal(array $segmentAbsolutePaths, string $outputAbsolute): ?array
    {
        return $this->buildFromSegments(
            $segmentAbsolutePaths,
            $outputAbsolute,
            [
                '-b:v', (string) config('rec.final_video_bitrate', '1600k'),
            ],
        );
    }

    /**
     * Concatenate prefix + current, then keep the last N seconds.
     * Used when SAVE happens early in a fresh 30s segment.
     *
     * Never use concat demuxer + stream copy: MediaRecorder segments restart PTS at 0,
     * which produces mid-clip jumps/freezes in browsers even when duration looks fine.
     */
    public function mergeAndTrim(string $prefixAbsolute, string $currentAbsolute, string $outputAbsolute, int $keepSeconds = 30): bool
    {
        if (! $this->ffmpegAvailable()) {
            return false;
        }

        $dir = dirname($outputAbsolute);
        $merged = $dir.DIRECTORY_SEPARATOR.'merged_raw_'.uniqid('', true).'.webm';
        $tmpOut = $dir.DIRECTORY_SEPARATOR.'merged_trim_'.uniqid('', true).'.webm';

        try {
            $concat = $this->concatSegments($prefixAbsolute, $currentAbsolute, $merged);

            if (! $concat || ! is_file($merged)) {
                Log::warning('REC merge failed', [
                    'prefix' => basename($prefixAbsolute),
                    'current' => basename($currentAbsolute),
                ]);

                return false;
            }

            $cut = $this->runFfmpeg([
                '-sseof', '-'.$keepSeconds,
                '-i', $merged,
                ...$this->webmEncodeArgs(),
                $tmpOut,
            ], 90);

            if (! $cut->successful() || ! is_file($tmpOut) || filesize($tmpOut) < 1) {
                Log::warning('REC merge trim failed', ['error' => $cut->errorOutput()]);

                if ($this->probeDurationSeconds($merged) === null) {
                    return false;
                }

                return @rename($merged, $outputAbsolute) || (@copy($merged, $outputAbsolute) && @unlink($merged));
            }

            if ($this->probeDurationSeconds($tmpOut) === null) {
                return false;
            }

            if (! @rename($tmpOut, $outputAbsolute)) {
                if (! @copy($tmpOut, $outputAbsolute)) {
                    return false;
                }
                @unlink($tmpOut);
            }

            Log::info('REC merge ok', [
                'bytes' => @filesize($outputAbsolute),
                'duration' => $this->probeDurationSeconds($outputAbsolute),
            ]);

            return true;
        } finally {
            if (is_file($merged)) {
                @unlink($merged);
            }
            if (is_file($tmpOut)) {
                @unlink($tmpOut);
            }
        }
    }

    /**
     * @param  list<string>  $segmentAbsolutePaths
     * @param  list<string>  $extraVideoArgs
     * @return array{duration_seconds: int, bytes: int}|null
     */
    private function buildFromSegments(array $segmentAbsolutePaths, string $outputAbsolute, array $extraVideoArgs = []): ?array
    {
        if (! $this->ffmpegAvailable()) {
            return null;
        }

        $paths = array_values(array_filter($segmentAbsolutePaths, 'is_file'));

        if ($paths === []) {
            return null;
        }

        $dir = dirname($outputAbsolute);
        @mkdir($dir, 0775, true);
        $tmpOut = $dir.DIRECTORY_SEPARATOR.'build_'.uniqid('', true).'.webm';

        try {
            if (count($paths) === 1) {
                $result = $this->runFfmpeg([
                    '-i', $paths[0],
                    ...$this->webmVideoEncodeArgs($extraVideoArgs),
                    '-c:a', 'libopus',
                    '-b:a', (string) config('rec.audio_bitrate', '96k'),
                    $tmpOut,
                ], 180);
            } else {
                $ok = $this->concatMany($paths, $tmpOut, $extraVideoArgs);
                $result = null;

                if (! $ok) {
                    return null;
                }
            }

            if ($result !== null && (! $result->successful() || ! is_file($tmpOut) || filesize($tmpOut) < 1)) {
                Log::warning('REC build ffmpeg failed', ['error' => $result->errorOutput()]);

                return null;
            }

            if (! is_file($tmpOut) || filesize($tmpOut) < 1) {
                return null;
            }

            $duration = $this->probeDurationSeconds($tmpOut);

            if ($duration === null) {
                return null;
            }

            if (! @rename($tmpOut, $outputAbsolute)) {
                if (! @copy($tmpOut, $outputAbsolute)) {
                    return null;
                }
                @unlink($tmpOut);
            }

            return [
                'duration_seconds' => (int) max(1, round($duration)),
                'bytes' => (int) (@filesize($outputAbsolute) ?: 0),
            ];
        } finally {
            if (is_file($tmpOut)) {
                @unlink($tmpOut);
            }
        }
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extraVideoArgs
     */
    private function concatMany(array $paths, string $outputAbsolute, array $extraVideoArgs = []): bool
    {
        if (count($paths) === 2) {
            return $this->concatSegments($paths[0], $paths[1], $outputAbsolute, $extraVideoArgs);
        }

        $current = $paths[0];
        $dir = dirname($outputAbsolute);

        for ($i = 1; $i < count($paths); $i++) {
            $tmp = $dir.DIRECTORY_SEPARATOR.'concat_step_'.uniqid('', true).'.webm';
            $ok = $this->concatSegments($current, $paths[$i], $tmp, $extraVideoArgs);

            if ($i > 1 && is_file($current) && str_contains($current, 'concat_step_')) {
                @unlink($current);
            }

            if (! $ok) {
                return false;
            }

            $current = $tmp;
        }

        if (! @rename($current, $outputAbsolute)) {
            return @copy($current, $outputAbsolute) && @unlink($current);
        }

        return true;
    }

    /**
     * Re-encode two WebM segments into one continuous file.
     *
     * @param  list<string>  $extraVideoArgs
     */
    private function concatSegments(
        string $prefixAbsolute,
        string $currentAbsolute,
        string $merged,
        array $extraVideoArgs = [],
    ): bool {
        $withAudio = $this->runFfmpeg([
            '-i', $prefixAbsolute,
            '-i', $currentAbsolute,
            '-filter_complex',
            '[0:v]setpts=PTS-STARTPTS[v0];'
            .'[1:v]setpts=PTS-STARTPTS[v1];'
            .'[0:a]asetpts=PTS-STARTPTS[a0];'
            .'[1:a]asetpts=PTS-STARTPTS[a1];'
            .'[v0][a0][v1][a1]concat=n=2:v=1:a=1[v][a]',
            '-map', '[v]',
            '-map', '[a]',
            ...$this->webmVideoEncodeArgs($extraVideoArgs),
            '-c:a', 'libopus',
            '-b:a', (string) config('rec.audio_bitrate', '96k'),
            $merged,
        ], 120);

        if ($withAudio->successful() && is_file($merged) && filesize($merged) > 0) {
            return true;
        }

        Log::warning('REC merge A/V concat failed, trying video-only', [
            'error' => $withAudio->errorOutput(),
        ]);

        if (is_file($merged)) {
            @unlink($merged);
        }

        $videoOnly = $this->runFfmpeg([
            '-i', $prefixAbsolute,
            '-i', $currentAbsolute,
            '-filter_complex',
            '[0:v]setpts=PTS-STARTPTS[v0];'
            .'[1:v]setpts=PTS-STARTPTS[v1];'
            .'[v0][v1]concat=n=2:v=1:a=0[v]',
            '-map', '[v]',
            '-an',
            ...$this->webmVideoEncodeArgs($extraVideoArgs),
            $merged,
        ], 120);

        if ($videoOnly->successful() && is_file($merged) && filesize($merged) > 0) {
            return true;
        }

        Log::warning('REC merge video-only concat failed', [
            'error' => $videoOnly->errorOutput(),
        ]);

        return false;
    }

    /**
     * @return list<string>
     */
    private function webmEncodeArgs(): array
    {
        return [
            ...$this->webmVideoEncodeArgs(),
            '-c:a', 'libopus',
            '-b:a', (string) config('rec.audio_bitrate', '96k'),
        ];
    }

    /**
     * @param  list<string>  $extra
     * @return list<string>
     */
    private function webmVideoEncodeArgs(array $extra = []): array
    {
        return [
            '-c:v', 'libvpx',
            '-b:v', (string) config('rec.final_video_bitrate', '1200k'),
            '-deadline', 'realtime',
            '-cpu-used', '8',
            '-auto-alt-ref', '0',
            ...$extra,
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private function runFfmpeg(array $args, int $timeoutSeconds)
    {
        return Process::timeout($timeoutSeconds)->run([
            'ffmpeg',
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            ...$args,
        ]);
    }

    public function ffmpegAvailable(bool $forceProbe = false): bool
    {
        if ($forceProbe) {
            $result = Process::timeout(5)->run(['ffmpeg', '-version']);

            return $result->successful();
        }

        return Cache::remember('rec:ffmpeg_available', 60, function () {
            $result = Process::timeout(5)->run(['ffmpeg', '-version']);

            return $result->successful();
        });
    }

    public function probeDurationSeconds(string $absolutePath): ?float
    {
        $result = Process::timeout(15)->run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolutePath,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $value = trim($result->output());

        return is_numeric($value) ? (float) $value : null;
    }
}
