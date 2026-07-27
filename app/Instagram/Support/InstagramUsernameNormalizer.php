<?php

namespace App\Instagram\Support;

use InvalidArgumentException;

class InstagramUsernameNormalizer
{
    private const PATTERN = '/^[a-z0-9._]{1,30}$/';

    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = trim($input);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', '', $value) ?? '';

        if (preg_match('#(?:https?://)?(?:www\.)?instagram\.com/([A-Za-z0-9._]+)/?#i', $value, $matches)) {
            $value = $matches[1];
        }

        $value = ltrim($value, '@');
        $value = strtolower($value);

        if ($value === '') {
            return null;
        }

        if (! preg_match(self::PATTERN, $value)) {
            throw new InvalidArgumentException('Username do Instagram inválido.');
        }

        if ($value[0] === '.' || str_ends_with($value, '.')) {
            throw new InvalidArgumentException('Username do Instagram inválido.');
        }

        return $value;
    }

    public static function tryNormalize(?string $input): ?string
    {
        try {
            return self::normalize($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function isValid(?string $input): bool
    {
        return self::tryNormalize($input) !== null;
    }

    public static function profileUrl(?string $username): ?string
    {
        $normalized = self::tryNormalize($username);

        return $normalized ? "https://instagram.com/{$normalized}" : null;
    }

    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            try {
                self::normalize((string) $value);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }
}
