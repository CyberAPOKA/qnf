<?php

namespace App\Services;

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
            // Re-encode the last N seconds so timestamps/metadata are continuous.
            // Stream-copy cuts on MediaRecorder WebM often leave players freezing mid-clip.
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

            if (! @rename($tmpOut, $absolute)) {
                @unlink($absolute);
                @rename($tmpOut, $absolute);
            }

            $duration = $this->probeDurationSeconds($absolute) ?? $keepSeconds;

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
                $outputAbsolute,
            ], 90);

            if (! $cut->successful() || ! is_file($outputAbsolute) || filesize($outputAbsolute) < 1) {
                Log::warning('REC merge trim failed', ['error' => $cut->errorOutput()]);

                // Prefer a continuous re-encoded merge over a broken trim.
                return @rename($merged, $outputAbsolute);
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
        }
    }

    /**
     * Re-encode two WebM segments into one continuous file.
     */
    private function concatSegments(string $prefixAbsolute, string $currentAbsolute, string $merged): bool
    {
        // Prefer A/V concat with normalized timestamps.
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
            ...$this->webmEncodeArgs(),
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

        // Some phone captures briefly lack an audio track on one segment.
        $videoOnly = $this->runFfmpeg([
            '-i', $prefixAbsolute,
            '-i', $currentAbsolute,
            '-filter_complex',
            '[0:v]setpts=PTS-STARTPTS[v0];'
            .'[1:v]setpts=PTS-STARTPTS[v1];'
            .'[v0][v1]concat=n=2:v=1:a=0[v]',
            '-map', '[v]',
            '-an',
            ...$this->webmVideoEncodeArgs(),
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
            '-b:a', '96k',
        ];
    }

    /**
     * @return list<string>
     */
    private function webmVideoEncodeArgs(): array
    {
        return [
            '-c:v', 'libvpx',
            '-b:v', '1200k',
            '-deadline', 'realtime',
            '-cpu-used', '8',
            '-auto-alt-ref', '0',
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

    public function ffmpegAvailable(): bool
    {
        $result = Process::timeout(5)->run(['ffmpeg', '-version']);

        return $result->successful();
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
