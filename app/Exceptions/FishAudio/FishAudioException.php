<?php

namespace App\Exceptions\FishAudio;

use RuntimeException;
use Throwable;

class FishAudioException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $transient = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::sanitize($message), $status ?? 0, $previous);
    }

    public static function sanitize(string $message): string
    {
        $key = (string) config('fish-audio.api_key');

        if ($key !== '') {
            $message = str_replace($key, '[redacted]', $message);
        }

        return preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
    }
}
