<?php

namespace App\Instagram\Enums;

enum InstagramTriggerType: string
{
    case DraftFinalized = 'draft-finalized';
    case MatchResult = 'match-result';

    public function label(): string
    {
        return match ($this) {
            self::DraftFinalized => 'Draft finalizado',
            self::MatchResult => 'Resultado da partida',
        };
    }
}
