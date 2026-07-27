<?php

namespace App\Instagram\Services;

use App\Instagram\Exceptions\InstagramAssetException;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\Storage;

class InstagramAssetService
{
    public function storePublicationDir(string $uuid): string
    {
        $relative = 'instagram/publications/'.$uuid;
        $absolute = $this->absolutePath($relative);

        if (! is_dir($absolute) && ! mkdir($absolute, 0755, true) && ! is_dir($absolute)) {
            throw new InstagramAssetException("Unable to create publication directory: {$relative}");
        }

        return $relative;
    }

    public function publicUrl(string $relativePath): ?string
    {
        $url = PublicStorage::url($relativePath);

        if (! $url) {
            return null;
        }

        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, 'http://')) {
            $httpsBase = $this->httpsBaseUrl();

            if ($httpsBase) {
                $path = parse_url($url, PHP_URL_PATH) ?: '/'.ltrim($relativePath, '/');

                return rtrim($httpsBase, '/').$path;
            }
        }

        return $url;
    }

    public function convertPngToJpeg(string $sourceAbsolute, string $destAbsolute, int $quality = 90): void
    {
        if (! is_file($sourceAbsolute)) {
            throw new InstagramAssetException("PNG source not found: {$sourceAbsolute}");
        }

        $image = @imagecreatefrompng($sourceAbsolute);

        if ($image === false) {
            throw new InstagramAssetException('Unable to read PNG source.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($image);

            throw new InstagramAssetException('Unable to create JPEG canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        $directory = dirname($destAbsolute);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($image);
            imagedestroy($canvas);

            throw new InstagramAssetException("Unable to create JPEG destination directory: {$directory}");
        }

        $quality = max(0, min(100, $quality));
        $saved = imagejpeg($canvas, $destAbsolute, $quality);

        imagedestroy($image);
        imagedestroy($canvas);

        if (! $saved || ! is_file($destAbsolute)) {
            throw new InstagramAssetException('Failed to write JPEG file.');
        }
    }

    public function resizeCoverJpeg(string $source, string $dest, int $w = 1080, int $h = 1350): void
    {
        if (! is_file($source)) {
            throw new InstagramAssetException("JPEG source not found: {$source}");
        }

        $info = @getimagesize($source);

        if ($info === false) {
            throw new InstagramAssetException('Unable to read source image for resize.');
        }

        $mime = (string) ($info['mime'] ?? '');
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            default => false,
        };

        if ($src === false) {
            throw new InstagramAssetException("Unsupported source MIME for resize: {$mime}");
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $scale = max($w / max(1, $srcW), $h / max(1, $srcH));
        $resizedW = (int) ceil($srcW * $scale);
        $resizedH = (int) ceil($srcH * $scale);

        $resized = imagecreatetruecolor($resizedW, $resizedH);

        if ($resized === false) {
            imagedestroy($src);

            throw new InstagramAssetException('Unable to create resized canvas.');
        }

        imagecopyresampled($resized, $src, 0, 0, 0, 0, $resizedW, $resizedH, $srcW, $srcH);

        $canvas = imagecreatetruecolor($w, $h);

        if ($canvas === false) {
            imagedestroy($src);
            imagedestroy($resized);

            throw new InstagramAssetException('Unable to create cover canvas.');
        }

        $offsetX = (int) floor(($resizedW - $w) / 2);
        $offsetY = (int) floor(($resizedH - $h) / 2);
        imagecopy($canvas, $resized, 0, 0, $offsetX, $offsetY, $w, $h);

        $directory = dirname($dest);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($src);
            imagedestroy($resized);
            imagedestroy($canvas);

            throw new InstagramAssetException("Unable to create resize destination directory: {$directory}");
        }

        $saved = imagejpeg($canvas, $dest, 90);

        imagedestroy($src);
        imagedestroy($resized);
        imagedestroy($canvas);

        if (! $saved || ! is_file($dest)) {
            throw new InstagramAssetException('Failed to write resized JPEG file.');
        }
    }

    public function cleanupPublication(string $uuid): void
    {
        $relative = 'instagram/publications/'.$uuid;

        if (Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->deleteDirectory($relative);
        }
    }

    public function absolutePath(string $relative): string
    {
        return storage_path('app/public/'.ltrim(str_replace('\\', '/', $relative), '/'));
    }

    private function httpsBaseUrl(): ?string
    {
        $fallback = config('filesystems.disks.public.fallback_url');

        if (is_string($fallback) && str_starts_with($fallback, 'https://')) {
            return rtrim($fallback, '/');
        }

        $appUrl = (string) config('app.url');

        if (str_starts_with($appUrl, 'https://')) {
            return rtrim($appUrl, '/');
        }

        if (is_string($fallback) && $fallback !== '') {
            return rtrim($fallback, '/');
        }

        return null;
    }
}
