<?php

namespace App\Instagram\Services;

use App\Instagram\Exceptions\InstagramAssetException;
use Illuminate\Support\Facades\Process;

class InstagramMediaValidator
{
    /**
     * @param  'feed'|'story'  $purpose
     */
    public function validateImage(string $absolutePath, string $purpose = 'feed'): void
    {
        if (! is_file($absolutePath)) {
            throw new InstagramAssetException("Image file not found: {$absolutePath}");
        }

        $maxBytes = (int) config('instagram.limits.image_max_bytes', 8 * 1024 * 1024);
        $size = filesize($absolutePath);

        if ($size === false || $size <= 0) {
            throw new InstagramAssetException('Image file is empty or unreadable.');
        }

        if ($size > $maxBytes) {
            throw new InstagramAssetException(sprintf(
                'Image exceeds max size of %d bytes (got %d).',
                $maxBytes,
                $size
            ));
        }

        $info = @getimagesize($absolutePath);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new InstagramAssetException('Unable to read image dimensions.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = (string) ($info['mime'] ?? '');

        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new InstagramAssetException("Unsupported image MIME type: {$mime}");
        }

        if ($purpose === 'story') {
            $this->assertStoryAspect($width, $height);
        } else {
            $this->assertFeedAspect($width, $height);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function validateVideoWithFfprobe(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new InstagramAssetException("Video file not found: {$absolutePath}");
        }

        $maxBytes = (int) config('instagram.limits.video_max_bytes', 100 * 1024 * 1024);
        $size = filesize($absolutePath);

        if ($size === false || $size <= 0) {
            throw new InstagramAssetException('Video file is empty or unreadable.');
        }

        if ($size > $maxBytes) {
            throw new InstagramAssetException(sprintf(
                'Video exceeds max size of %d bytes (got %d).',
                $maxBytes,
                $size
            ));
        }

        $probe = $this->ffprobe($absolutePath);
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];

        $duration = (float) ($format['duration'] ?? 0);
        $minSeconds = (float) config('instagram.limits.video_min_seconds', 3);
        $maxSeconds = (float) config('instagram.limits.video_max_seconds', 60);

        if ($duration < $minSeconds || $duration > $maxSeconds) {
            throw new InstagramAssetException(sprintf(
                'Video duration must be between %.0f and %.0f seconds (got %.2f).',
                $minSeconds,
                $maxSeconds,
                $duration
            ));
        }

        $videoStream = $this->firstStream($streams, 'video');
        $audioStream = $this->firstStream($streams, 'audio');

        if ($videoStream === null) {
            throw new InstagramAssetException('Video has no video stream.');
        }

        $codecName = strtolower((string) ($videoStream['codec_name'] ?? ''));
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        if (! in_array($codecName, ['h264', 'hevc', 'h265'], true)) {
            throw new InstagramAssetException("Unsupported video codec: {$codecName}");
        }

        if ($width <= 0 || $height <= 0) {
            throw new InstagramAssetException('Video stream missing dimensions.');
        }

        if ($audioStream !== null) {
            $audioCodec = strtolower((string) ($audioStream['codec_name'] ?? ''));

            if ($audioCodec !== '' && ! in_array($audioCodec, ['aac', 'mp3'], true)) {
                throw new InstagramAssetException("Unsupported audio codec: {$audioCodec}");
            }
        }

        $probe['validation'] = [
            'duration' => $duration,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'video_codec' => $codecName,
            'audio_codec' => isset($audioStream['codec_name']) ? (string) $audioStream['codec_name'] : null,
            'preferred_resolution' => $width === 1080 && $height === 1920,
            'preferred_codecs' => $codecName === 'h264' && (
                $audioStream === null
                || strtolower((string) ($audioStream['codec_name'] ?? '')) === 'aac'
            ),
        ];

        return $probe;
    }

    private function assertFeedAspect(int $width, int $height): void
    {
        if ($width < 320 || $width > 1440) {
            throw new InstagramAssetException(sprintf(
                'Feed image width must be between 320 and 1440px (got %dx%d).',
                $width,
                $height
            ));
        }

        $ratio = $width / max(1, $height);
        $minRatio = 4 / 5;
        $maxRatio = 1.91;

        if ($ratio < ($minRatio - 0.01) || $ratio > ($maxRatio + 0.01)) {
            throw new InstagramAssetException(sprintf(
                'Feed image aspect ratio must be between 4:5 and 1.91:1 (got %dx%d).',
                $width,
                $height
            ));
        }
    }

    private function assertStoryAspect(int $width, int $height): void
    {
        $ratio = $width / max(1, $height);
        $target = 9 / 16;

        if (abs($ratio - $target) > 0.05) {
            throw new InstagramAssetException(sprintf(
                'Story image aspect ratio must be approximately 9:16 (got %dx%d).',
                $width,
                $height
            ));
        }

        if ($width < 320 || $height < 320) {
            throw new InstagramAssetException(sprintf(
                'Story image dimensions are too small (%dx%d).',
                $width,
                $height
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ffprobe(string $absolutePath): array
    {
        $binary = (string) config('instagram.ffmpeg.ffprobe_binary', 'ffprobe');
        $timeout = (int) config('instagram.ffmpeg.timeout', 180);

        $result = Process::timeout($timeout)->run([
            $binary,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $absolutePath,
        ]);

        if (! $result->successful()) {
            throw new InstagramAssetException(
                'ffprobe failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded)) {
            throw new InstagramAssetException('ffprobe returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    private function firstStream(array $streams, string $type): ?array
    {
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }

        return null;
    }
}
