<?php

namespace App\Services\DraftNarration;

use App\Enums\NarratorVoice;
use App\Models\Team;

class DraftNarrationTemplate
{
    /**
     * Closing lines are parody/AI-generated humor for the QNF group,
     * not real statements by the public figures being impersonated.
     *
     * @return array<string, list<string>>
     */
    public function closingLines(): array
    {
        return [
            NarratorVoice::LULA->value => [
                'Se esse time ganhar, eu vou liberar picanha de graça para toda a QNF.',
            ],
            NarratorVoice::BOLSONARO->value => [
                'Se esse time ganhar, eu vou fazer um churrasco na QNF, e ponto final.',
            ],
        ];
    }

    public function closingLine(NarratorVoice $voice, ?Team $team = null): string
    {
        $lines = $this->closingLines()[$voice->value] ?? [];

        if ($lines === []) {
            return '';
        }

        $index = $team ? $team->id % count($lines) : 0;

        return $lines[$index];
    }
}
