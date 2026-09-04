<?php

namespace App\WhatsApp\Support;

use App\Enums\NarratorVoice;
use App\Enums\Position;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

class LineupNarrationBuilder
{
    public function build(Game $game, Team $team, NarratorVoice $voice): ?string
    {
        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);
        $team->loadMissing('captain');

        $players = $this->teamPlayers($game, $team);

        if ($players->isEmpty()) {
            return null;
        }

        $line = $players->reject(fn (User $user) => $user->position === Position::GOALKEEPER);
        $goalkeepers = $players->filter(fn (User $user) => $user->position === Position::GOALKEEPER);

        $colorLabel = mb_strtolower($team->color->label());
        $roster = $this->formatRoster($line, $goalkeepers);

        return "Escalação do time {$colorLabel}: {$roster} {$this->suffix($voice)}";
    }

    /**
     * @return Collection<int, User>
     */
    private function teamPlayers(Game $game, Team $team): Collection
    {
        $players = collect();

        if ($team->captain) {
            $players->push($team->captain);
        }

        $picks = $game->draftPicks
            ->where('team_color', $team->color)
            ->sortBy('id')
            ->map(fn ($pick) => $pick->pickedUser)
            ->filter();

        return $players->concat($picks)->unique('id')->values();
    }

    /**
     * @param  Collection<int, User>  $line
     * @param  Collection<int, User>  $goalkeepers
     */
    private function formatRoster(Collection $line, Collection $goalkeepers): string
    {
        $lineNames = $this->names($line);
        $gkNames = $this->names($goalkeepers);

        if ($gkNames === []) {
            return $this->joinWithAnd($lineNames).'.';
        }

        $gkJoined = $this->joinWithAnd($gkNames);

        if ($lineNames === []) {
            return "no gol {$gkJoined}.";
        }

        return implode(', ', $lineNames)." e no gol {$gkJoined}.";
    }

    /**
     * @param  Collection<int, User>  $players
     * @return list<string>
     */
    private function names(Collection $players): array
    {
        return $players
            ->map(fn (User $user) => $this->sanitizeName((string) $user->name))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $names
     */
    private function joinWithAnd(array $names): string
    {
        $names = array_values($names);

        if ($names === []) {
            return '';
        }

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' e '.$last;
    }

    private function suffix(NarratorVoice $voice): string
    {
        return match ($voice) {
            NarratorVoice::LULA => 'Se esse time ganhar eu vou liberar picanha para toda a QNF.',
            NarratorVoice::BOLSONARO => 'Brasil acima de tudo, Deus acima de todos, ihuuuuu hahahaha ta ok!',
            NarratorVoice::NEYMAR => '',
        };
    }

    private function sanitizeName(string $name): string
    {
        $name = str_replace(['*', '#', '`', '_'], '', $name);
        $name = str_replace('-', ' ', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
