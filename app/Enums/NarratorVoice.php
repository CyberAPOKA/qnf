<?php

namespace App\Enums;

enum NarratorVoice: string
{
    case LULA = 'lula';
    case BOLSONARO = 'bolsonaro';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromConfig(?string $value = null): self
    {
        $value = strtolower(trim((string) ($value ?? config('fish-audio.narrator', self::LULA->value))));

        // Reserved for a future random picker; keep drafts deterministic for now.
        if ($value === 'random') {
            return self::LULA;
        }

        return self::tryFrom($value) ?? self::LULA;
    }
}
