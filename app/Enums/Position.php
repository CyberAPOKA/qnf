<?php

namespace App\Enums;

enum Position: string
{
    case GOALKEEPER = 'goalkeeper';
    case FIXED = 'fixed';
    case WINGER = 'winger';
    case PIVOT = 'pivot';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        return [
            self::GOALKEEPER->value => 'Goleiro',
            self::FIXED->value => 'Fixo',
            self::WINGER->value => 'Ala',
            self::PIVOT->value => 'Pivô',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value] ?? $this->value;
    }
}
