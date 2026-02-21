<?php

namespace App\Enums;

enum GameStatus: string
{
    case SCHEDULED = 'scheduled';
    case OPEN = 'open';
    case FULL = 'full';
    case DRAFTING = 'drafting';
    case DONE = 'done';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        return [
            self::SCHEDULED->value => 'Agendado',
            self::OPEN->value => 'Aberto',
            self::FULL->value => 'Lotado',
            self::DRAFTING->value => 'Draft',
            self::DONE->value => 'Finalizado',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value] ?? $this->value;
    }
}
