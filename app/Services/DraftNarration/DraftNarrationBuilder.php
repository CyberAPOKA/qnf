<?php

namespace App\Services\DraftNarration;

use App\Enums\GameStatus;
use App\Enums\NarratorVoice;
use App\Enums\Position;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

class DraftNarrationBuilder
{
    public function __construct(
        private readonly DraftNarrationTemplate $templates,
    ) {}

    public function build(Game $game, Team $team, NarratorVoice $voice): string
    {
        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);
        $team->loadMissing('captain');

        $colorLabel = mb_strtolower($team->color->label());
        $lines = [
            "Convocação do time {$colorLabel}.",
            '',
        ];

        foreach ($this->rosterLines($game, $team) as $line) {
            $lines[] = $line;
            $lines[] = '';
        }

        $closing = $this->templates->closingLine($voice, $team);

        if ($closing !== '') {
            $lines[] = $closing;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function rosterLines(Game $game, Team $team): array
    {
        $championIds = $this->currentChampionUserIds($game);
        $players = $this->teamPlayers($game, $team);
        $byPosition = $players->groupBy(fn (User $user) => $user->position?->value);

        $lines = [];

        $lines = array_merge($lines, $this->linesFor(
            $byPosition->get(Position::GOALKEEPER->value),
            'Goleiro',
            $championIds,
        ));

        $lines = array_merge($lines, $this->linesFor(
            $byPosition->get(Position::FIXED->value),
            'Fixo',
            $championIds,
        ));

        $wingers = Collection::make($byPosition->get(Position::WINGER->value, collect()))->values();

        if ($wingers->count() === 1) {
            $lines[] = $this->playerLine('Ala', $wingers[0], $championIds);
        } elseif ($wingers->count() >= 2) {
            $lines[] = $this->playerLine('Ala esquerdo', $wingers[0], $championIds);
            $lines[] = $this->playerLine('Ala direito', $wingers[1], $championIds);

            for ($index = 2; $index < $wingers->count(); $index++) {
                $lines[] = $this->playerLine('Ala', $wingers[$index], $championIds);
            }
        }

        $lines = array_merge($lines, $this->linesFor(
            $byPosition->get(Position::PIVOT->value),
            'Pivô',
            $championIds,
        ));

        return array_values(array_filter($lines));
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
     * @param  Collection<int, User>|null  $players
     * @param  list<int>  $championIds
     * @return list<string>
     */
    private function linesFor(?Collection $players, string $label, array $championIds): array
    {
        if (! $players || $players->isEmpty()) {
            return [];
        }

        return $players
            ->values()
            ->map(fn (User $user) => $this->playerLine($label, $user, $championIds))
            ->all();
    }

    /**
     * @param  list<int>  $championIds
     */
    private function playerLine(string $label, User $user, array $championIds): string
    {
        $name = $this->sanitizeName((string) $user->name);
        $suffix = in_array($user->id, $championIds, true)
            ? ', o atual campeão da QNF'
            : '';

        return "{$label}: {$name}{$suffix}.";
    }

    /**
     * @return list<int>
     */
    private function currentChampionUserIds(Game $game): array
    {
        $previous = Game::query()
            ->where('status', GameStatus::DONE)
            ->where('id', '<', $game->id)
            ->orderByDesc('id')
            ->with(['teams', 'draftPicks'])
            ->first();

        if (! $previous) {
            return [];
        }

        $scored = $previous->teams->filter(fn (Team $team) => $team->score !== null);

        if ($scored->isEmpty()) {
            return [];
        }

        $maxScore = (int) $scored->max('score');
        $winners = $scored->filter(fn (Team $team) => (int) $team->score === $maxScore);

        if ($winners->count() === $scored->count()) {
            return [];
        }

        $ids = [];

        foreach ($winners as $winner) {
            if ($winner->captain_user_id) {
                $ids[] = (int) $winner->captain_user_id;
            }

            foreach ($previous->draftPicks->where('team_color', $winner->color) as $pick) {
                $ids[] = (int) $pick->picked_user_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function sanitizeName(string $name): string
    {
        $name = str_replace(['*', '#', '`', '_'], '', $name);
        $name = str_replace('-', ' ', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
