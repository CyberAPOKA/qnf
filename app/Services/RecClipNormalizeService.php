<?php

namespace App\Services;

use App\Models\RecClip;
use App\Support\PublicStorage;
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

    private bool $ffmpegResolved = false;

    private ?string $ffmpegBinary = null;

    private function ffmpegBinary(): ?string
    {
        if ($this->ffmpegResolved) {
            return $this->ffmpegBinary;
        }

        $this->ffmpegResolved = true;

        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $bin) {
            try {
                $result = Process::timeout(5)->run([$bin, '-version']);

                if ($result->successful()) {
                    $this->ffmpegBinary = $bin;

                    return $bin;
                }
            } catch (\Throwable) {
                // proc_open disabled, missing binary, empty php-fpm PATH, etc.
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $args
     */
    private function runFfmpeg(array $args, int $timeoutSeconds)
    {
        $bin = $this->ffmpegBinary();

        if (! $bin) {
            throw new \RuntimeException('ffmpeg not available');
        }

        return Process::timeout($timeoutSeconds)->run([
            $bin,
            '-nostdin',
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            ...$args,
        ]);
    }

    public function ffmpegAvailable(): bool
    {
        return $this->ffmpegBinary() !== null;
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

    /**
     * @return array{width: int, height: int}|null
     */
    public function probeVideoSize(string $absolutePath): ?array
    {
        $result = Process::timeout(15)->run([
            'ffprobe',
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height',
            '-of', 'csv=p=0:s=x',
            $absolutePath,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $value = trim($result->output());

        if (! preg_match('/^(\d+)x(\d+)$/', $value, $matches)) {
            return null;
        }

        return [
            'width' => (int) $matches[1],
            'height' => (int) $matches[2],
        ];
    }

    /**
     * Rotate a stored clip 90° or 180°. Video must be re-encoded.
     *
     * @param  'cw'|'ccw'|'180'  $direction
     * @return array{ok: bool, error: ?string}
     */
    public function rotate(string $relativePath, string $direction = 'cw'): array
    {
        if (! $this->ffmpegAvailable()) {
            return ['ok' => false, 'error' => 'ffmpeg não encontrado'];
        }

        $disk = Storage::disk('public');
        $absolute = $disk->exists($relativePath)
            ? $disk->path($relativePath)
            : PublicStorage::localPath($relativePath);

        if (! $absolute || ! is_file($absolute)) {
            return ['ok' => false, 'error' => 'arquivo ausente'];
        }

        $filter = match ($direction) {
            'ccw' => 'transpose=2',
            '180' => 'hflip,vflip',
            default => 'transpose=1',
        };
        $extension = pathinfo($absolute, PATHINFO_EXTENSION) ?: 'webm';
        $tmpDir = storage_path('app/tmp/rec-rotate');

        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            return ['ok' => false, 'error' => 'sem permissão para criar '.$tmpDir.'. Rode: sudo -u www-data php artisan rec:rotate-clips'];
        }

        $tmpOut = $tmpDir.DIRECTORY_SEPARATOR.'rot_'.uniqid('', true).'.'.$extension;
        $lastError = null;

        $attempts = [
            [
                '-i', $absolute,
                '-vf', $filter,
                ...$this->webmEncodeArgs(),
                $tmpOut,
            ],
            [
                '-i', $absolute,
                '-vf', $filter,
                ...$this->webmVideoEncodeArgs(),
                '-an',
                $tmpOut,
            ],
        ];

        try {
            foreach ($attempts as $args) {
                if (is_file($tmpOut)) {
                    @unlink($tmpOut);
                }

                $result = $this->runFfmpeg($args, 180);
                $lastError = trim($result->errorOutput() ?: $result->output()) ?: 'exit '.$result->exitCode();

                if ($result->successful() && is_file($tmpOut) && filesize($tmpOut) > 0) {
                    if (! @rename($tmpOut, $absolute) && ! @copy($tmpOut, $absolute)) {
                        return [
                            'ok' => false,
                            'error' => 'Permission denied ao substituir o arquivo. Rode: sudo -u www-data php artisan rec:rotate-clips',
                        ];
                    }

                    Log::info('REC rotate ok', [
                        'path' => $relativePath,
                        'direction' => $direction,
                        'bytes' => @filesize($absolute),
                    ]);

                    return ['ok' => is_file($absolute) && filesize($absolute) > 0, 'error' => null];
                }
            }

            Log::warning('REC rotate ffmpeg failed', [
                'path' => $relativePath,
                'error' => $lastError,
            ]);

            return ['ok' => false, 'error' => $lastError];
        } finally {
            if (is_file($tmpOut)) {
                @unlink($tmpOut);
            }
        }
    }

    public function mp4RelativePath(RecClip $clip): string
    {
        return "rec/converted/{$clip->id}.mp4";
    }

    /**
     * Convert the original WebM to a cached H.264 MP4 for iPhone download/Photos.
     */
    public function ensureMp4(RecClip $clip): ?string
    {
        @set_time_limit(180);

        $disk = Storage::disk('public');
        $publicRelative = $this->mp4RelativePath($clip);
        $publicAbsolute = $disk->path($publicRelative);

        $webm = PublicStorage::localPath($clip->file_path)
            ?? ($disk->exists($clip->file_path) ? $disk->path($clip->file_path) : null);

        $cacheAbsolute = $this->writableMp4Path($clip, $webm, $publicAbsolute);

        foreach (array_filter([$publicAbsolute, $cacheAbsolute]) as $cached) {
            if (is_file($cached) && filesize($cached) > 0) {
                return $cached;
            }
        }

        if (! $webm || ! is_file($webm)) {
            Log::warning('REC mp4 skipped: source missing', [
                'clip_id' => $clip->id,
                'file_path' => $clip->file_path,
            ]);

            return null;
        }

        if (! $cacheAbsolute) {
            Log::error('REC mp4 skipped: no writable output dir', ['clip_id' => $clip->id]);

            return null;
        }

        if (! $this->ffmpegAvailable()) {
            Log::warning('REC mp4 skipped: ffmpeg not available', ['clip_id' => $clip->id]);

            return null;
        }

        // Temp MUST end in .mp4 — ffmpeg guesses the muxer from the extension.
        $tmpOut = dirname($cacheAbsolute).DIRECTORY_SEPARATOR.$clip->id.'.converting.mp4';

        $videoArgs = [
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '18',
            '-pix_fmt', 'yuv420p',
        ];

        $attempts = [
            [
                '-analyzeduration', '100M',
                '-probesize', '100M',
                '-i', $webm,
                ...$videoArgs,
                '-c:a', 'aac',
                '-b:a', '192k',
                '-movflags', '+faststart',
                '-f', 'mp4',
                $tmpOut,
            ],
            [
                '-analyzeduration', '100M',
                '-probesize', '100M',
                '-i', $webm,
                ...$videoArgs,
                '-an',
                '-movflags', '+faststart',
                '-f', 'mp4',
                $tmpOut,
            ],
        ];

        try {
            foreach ($attempts as $args) {
                if (is_file($tmpOut)) {
                    @unlink($tmpOut);
                }

                $result = $this->runFfmpeg($args, 180);
                $wroteFile = is_file($tmpOut) && filesize($tmpOut) > 0;

                if ($wroteFile) {
                    if (! @rename($tmpOut, $cacheAbsolute)) {
                        @copy($tmpOut, $cacheAbsolute);
                    }

                    if (is_file($cacheAbsolute) && filesize($cacheAbsolute) > 0) {
                        if ($cacheAbsolute !== $publicAbsolute) {
                            try {
                                $disk->makeDirectory('rec/converted');
                                @copy($cacheAbsolute, $publicAbsolute);
                            } catch (\Throwable) {
                            }
                        }

                        Log::info('REC mp4 ok', [
                            'clip_id' => $clip->id,
                            'path' => $cacheAbsolute,
                            'bytes' => filesize($cacheAbsolute),
                        ]);

                        return $cacheAbsolute;
                    }
                }

                Log::warning('REC mp4 ffmpeg failed', [
                    'clip_id' => $clip->id,
                    'exit' => $result->exitCode(),
                    'error' => trim($result->errorOutput() ?: $result->output()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('REC mp4 exception', [
                'clip_id' => $clip->id,
                'message' => $e->getMessage(),
            ]);
        } finally {
            if (is_file($tmpOut)) {
                @unlink($tmpOut);
            }
        }

        return null;
    }

    private function writableMp4Path(RecClip $clip, ?string $webm, string $publicAbsolute): ?string
    {
        $candidates = array_values(array_filter([
            $publicAbsolute,
            $webm ? dirname($webm).DIRECTORY_SEPARATOR.$clip->id.'.mp4' : null,
            storage_path('framework/cache/rec-converted/'.$clip->id.'.mp4'),
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'rec-'.$clip->id.'.mp4',
        ]));

        foreach ($candidates as $path) {
            $dir = dirname($path);

            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            if (is_dir($dir) && is_writable($dir)) {
                return $path;
            }
        }

        return null;
    }
}
