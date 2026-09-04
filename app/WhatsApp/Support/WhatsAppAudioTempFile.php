<?php

namespace App\WhatsApp\Support;

use RuntimeException;

class WhatsAppAudioTempFile
{
    public static function directory(): string
    {
        $directory = storage_path('app/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        @chmod($directory, 0755);

        return $directory;
    }

    public static function put(string $contents, string $prefix = 'voice'): string
    {
        $path = self::path($prefix, 'mp3');

        file_put_contents($path, $contents);
        @chmod($path, 0644);

        return $path;
    }

    public static function copyFrom(string $sourcePath): string
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'mp3';
        $path = self::path('send', $extension);

        if (! copy($sourcePath, $path)) {
            throw new RuntimeException("Failed to stage WhatsApp audio: {$sourcePath}");
        }

        @chmod($path, 0644);

        return $path;
    }

    private static function path(string $prefix, string $extension): string
    {
        return self::directory().DIRECTORY_SEPARATOR.$prefix.'-'.uniqid('', true).'.'.$extension;
    }
}
