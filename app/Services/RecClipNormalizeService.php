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
        $tmpConcat = $dir.DIRECTORY_SEPARATOR.'tmp_concat_'.uniqid('', true).'.webm';
        $tmpOut = $dir.DIRECTORY_SEPARATOR.'tmp_out_'.uniqid('', true).'.webm';

        try {
            // Remux + take the last N seconds (where the real frames are).
            $cut = Process::timeout(60)->run([
                'ffmpeg',
                '-y',
                '-sseof', '-'.$keepSeconds,
                '-i', $absolute,
                '-c', 'copy',
                $tmpOut,
            ]);

            if (! $cut->successful() || ! is_file($tmpOut) || filesize($tmpOut) < 1) {
                // Fallback: remux whole file to fix metadata, then try again.
                $remux = Process::timeout(60)->run([
                    'ffmpeg',
                    '-y',
                    '-i', $absolute,
                    '-c', 'copy',
                    $tmpConcat,
                ]);

                if (! $remux->successful() || ! is_file($tmpConcat)) {
                    Log::warning('REC normalize ffmpeg failed', [
                        'path' => $relativePath,
                        'cut' => $cut->errorOutput(),
                        'remux' => $remux->errorOutput(),
                    ]);

                    return null;
                }

                $cut = Process::timeout(60)->run([
                    'ffmpeg',
                    '-y',
                    '-sseof', '-'.$keepSeconds,
                    '-i', $tmpConcat,
                    '-c', 'copy',
                    $tmpOut,
                ]);

                if (! $cut->successful() || ! is_file($tmpOut) || filesize($tmpOut) < 1) {
                    Log::warning('REC normalize cut failed after remux', [
                        'path' => $relativePath,
                        'error' => $cut->errorOutput(),
                    ]);

                    return null;
                }
            }

            // Replace original atomically.
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
            if (is_file($tmpConcat)) {
                @unlink($tmpConcat);
            }
            if (is_file($tmpOut)) {
                @unlink($tmpOut);
            }
        }
    }

    /**
     * Concatenate prefix + current, then keep the last N seconds.
     * Used when SAVE happens early in a fresh 30s segment.
     */
    public function mergeAndTrim(string $prefixAbsolute, string $currentAbsolute, string $outputAbsolute, int $keepSeconds = 30): bool
    {
        if (! $this->ffmpegAvailable()) {
            return false;
        }

        $listFile = dirname($outputAbsolute).DIRECTORY_SEPARATOR.'concat_'.uniqid('', true).'.txt';
        $merged = dirname($outputAbsolute).DIRECTORY_SEPARATOR.'merged_'.uniqid('', true).'.webm';

        try {
            $content = "file '".str_replace("'", "'\\''", $prefixAbsolute)."'\n"
                ."file '".str_replace("'", "'\\''", $currentAbsolute)."'\n";
            file_put_contents($listFile, $content);

            $concat = Process::timeout(90)->run([
                'ffmpeg',
                '-y',
                '-f', 'concat',
                '-safe', '0',
                '-i', $listFile,
                '-c', 'copy',
                $merged,
            ]);

            if (! $concat->successful() || ! is_file($merged)) {
                // Re-encode concat fallback (more compatible across MediaRecorder segments).
                $concat = Process::timeout(120)->run([
                    'ffmpeg',
                    '-y',
                    '-i', $prefixAbsolute,
                    '-i', $currentAbsolute,
                    '-filter_complex', '[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[v][a]',
                    '-map', '[v]',
                    '-map', '[a]',
                    $merged,
                ]);
            }

            if (! $concat->successful() || ! is_file($merged)) {
                Log::warning('REC merge failed', ['error' => $concat->errorOutput()]);

                return false;
            }

            $cut = Process::timeout(60)->run([
                'ffmpeg',
                '-y',
                '-sseof', '-'.$keepSeconds,
                '-i', $merged,
                '-c', 'copy',
                $outputAbsolute,
            ]);

            if (! $cut->successful() || ! is_file($outputAbsolute)) {
                // Last resort: copy merged as-is.
                return @rename($merged, $outputAbsolute);
            }

            return true;
        } finally {
            if (is_file($listFile)) {
                @unlink($listFile);
            }
            if (is_file($merged)) {
                @unlink($merged);
            }
        }
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
