<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class PublicStorage
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            return $disk->url($path);
        }

        $fallbackUrl = config('filesystems.disks.public.fallback_url');

        if ($fallbackUrl) {
            return rtrim($fallbackUrl, '/').'/'.ltrim($path, '/');
        }

        return $disk->url($path);
    }

    public static function browserUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/storage/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    public static function urls(array $paths): array
    {
        return array_values(array_filter(
            array_map(fn (string $path) => self::url($path), $paths)
        ));
    }

    public static function localPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $localPath = storage_path('app/public/'.ltrim($path, '/'));

        if (file_exists($localPath)) {
            return $localPath;
        }

        $fallbackUrl = config('filesystems.disks.public.fallback_url');

        if (! $fallbackUrl) {
            return null;
        }

        $remoteUrl = rtrim($fallbackUrl, '/').'/'.ltrim($path, '/');
        $contents = @file_get_contents($remoteUrl);

        if ($contents === false) {
            return null;
        }

        $directory = dirname($localPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($localPath, $contents);

        return $localPath;
    }
}
