<?php

namespace App\WhatsApp\Support;

use App\Enums\NarratorVoice;
use App\Enums\TeamColor;

readonly class LineupArguments
{
    public function __construct(
        public TeamColor $color,
        public NarratorVoice $voice,
    ) {}

    public static function parse(?string $argument): ?self
    {
        if ($argument === null || trim($argument) === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', strtolower(trim($argument))) ?: [];

        $color = null;
        $voice = null;

        foreach ($tokens as $token) {
            $normalized = ltrim((string) $token, '-');

            if ($normalized === '') {
                continue;
            }

            $parsedColor = self::colorFrom($normalized);
            $parsedVoice = NarratorVoice::tryFrom($normalized);

            if ($parsedColor && $color === null) {
                $color = $parsedColor;

                continue;
            }

            if ($parsedVoice && $voice === null) {
                $voice = $parsedVoice;
            }
        }

        if (! $color || ! $voice) {
            return null;
        }

        return new self($color, $voice);
    }

    private static function colorFrom(string $token): ?TeamColor
    {
        return match ($token) {
            'blue', 'azul' => TeamColor::BLUE,
            'yellow', 'amarelo' => TeamColor::YELLOW,
            'green', 'verde' => TeamColor::GREEN,
            default => TeamColor::tryFrom($token),
        };
    }
}
