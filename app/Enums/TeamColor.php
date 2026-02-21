<?php

namespace App\Enums;

enum TeamColor: string
{
    case GREEN = 'green';
    case YELLOW = 'yellow';
    case BLUE = 'blue';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        return [
            self::GREEN->value => 'Verde',
            self::YELLOW->value => 'Amarelo',
            self::BLUE->value => 'Azul',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value] ?? $this->value;
    }
}
