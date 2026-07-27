<?php

namespace App\Instagram\Services;

use App\Instagram\Exceptions\InstagramAssetException;
use Illuminate\Support\Facades\Process;

class InstagramStoryVideoService
{
    public function build(
        string $imageAbsolutePath,
        ?string $audioAbsolutePath,
        string $outputAbsolutePath,
        int $durationSeconds,
    ): string {
        if (! is_file($imageAbsolutePath)) {
            throw new InstagramAssetException("Story image not found: {$imageAbsolutePath}");
        }

        if ($audioAbsolutePath !== null && $audioAbsolutePath !== '' && ! is_file($audioAbsolutePath)) {
            throw new InstagramAssetException("Story audio not found: {$audioAbsolutePath}");
        }

        $durationSeconds = max(
            (int) config('instagram.limits.video_min_seconds', 3),
            min((int) config('instagram.limits.video_max_seconds', 60), $durationSeconds)
        );

        $directory = dirname($outputAbsolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InstagramAssetException("Unable to create output directory: {$directory}");
        }

        $args = $audioAbsolutePath
            ? $this->argsWithAudio($imageAbsolutePath, $audioAbsolutePath, $outputAbsolutePath, $durationSeconds)
            : $this->argsSilent($imageAbsolutePath, $outputAbsolutePath, $durationSeconds);

        $binary = (string) config('instagram.ffmpeg.binary', 'ffmpeg');
        $timeout = (int) config('instagram.ffmpeg.timeout', 180);

        $result = Process::timeout($timeout)->run([
            $binary,
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            ...$args,
        ]);

        if (! $result->successful() || ! is_file($outputAbsolutePath)) {
            $error = trim($result->errorOutput() ?: $result->output()) ?: 'unknown ffmpeg error';

            throw new InstagramAssetException("Failed to build story video: {$error}");
        }

        return $outputAbsolutePath;
    }

    /**
     * @return list<string>
     */
    private function argsWithAudio(
        string $imageAbsolutePath,
        string $audioAbsolutePath,
        string $outputAbsolutePath,
        int $durationSeconds,
    ): array {
        return [
            '-loop', '1',
            '-i', $imageAbsolutePath,
            '-stream_loop', '-1',
            '-i', $audioAbsolutePath,
            '-vf', $this->videoFilter(),
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '22',
            '-r', '30',
            '-g', '60',
            '-sc_threshold', '0',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-b:a', '128k',
            '-af', 'loudnorm=I=-16:LRA=11:TP=-1.5',
            '-t', (string) $durationSeconds,
            '-shortest',
            '-movflags', '+faststart',
            $outputAbsolutePath,
        ];
    }

    /**
     * @return list<string>
     */
    private function argsSilent(
        string $imageAbsolutePath,
        string $outputAbsolutePath,
        int $durationSeconds,
    ): array {
        return [
            '-loop', '1',
            '-i', $imageAbsolutePath,
            '-f', 'lavfi',
            '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000',
            '-vf', $this->videoFilter(),
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '22',
            '-r', '30',
            '-g', '60',
            '-sc_threshold', '0',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-b:a', '128k',
            '-t', (string) $durationSeconds,
            '-shortest',
            '-movflags', '+faststart',
            $outputAbsolutePath,
        ];
    }

    private function videoFilter(): string
    {
        return 'scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,format=yuv420p';
    }
}
