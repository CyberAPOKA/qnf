<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Events\DraftFinished;
use App\Events\DraftPickMade;
use App\Events\DraftTurnChanged;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Support\GamePayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DraftService
{
    public const SNAKE_SEQUENCE = [
        TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE,
        TeamColor::BLUE, TeamColor::YELLOW, TeamColor::GREEN,
        TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE,
        TeamColor::BLUE, TeamColor::YELLOW, TeamColor::GREEN,
    ];

    public function drawCaptains(Game $game): array
    {
        if ($game->status !== GameStatus::FULL) {
            throw ValidationException::withMessages([
                'game' => 'O jogo precisa estar lotado para sortear capitães.',
            ]);
        }

        $candidates = $game->players()
            ->where('position', '!=', Position::GOALKEEPER)
            ->inRandomOrder()
            ->take(3)
            ->get();

        if ($candidates->count() < 3) {
            throw ValidationException::withMessages([
                'captains' => 'Não há jogadores de linha suficientes para sortear 3 capitães.',
            ]);
        }

        $colors = TeamColor::cases();

        DB::transaction(function () use ($game, $candidates, $colors): void {
            foreach ($colors as $index => $color) {
                Team::updateOrCreate(
                    ['game_id' => $game->id, 'color' => $color],
                    [
                        'captain_user_id' => $candidates[$index]->id,
                        'pick_order' => $index + 1,
                    ]
                );
            }

            $game->update(['status' => GameStatus::DRAFTING]);
        });

        return $candidates->values()->all();
    }

    public function currentTurnColor(Game $game): ?TeamColor
    {
        $totalPicks = $game->draftPicks()->count();

        if ($totalPicks >= 12) {
            return null;
        }

        return self::SNAKE_SEQUENCE[$totalPicks];
    }

    public function canPick(User $actor, Game $game): bool
    {
        if ($game->status !== GameStatus::DRAFTING) {
            return false;
        }

        if ($actor->role === 'admin') {
            return true;
        }

        $turnColor = $this->currentTurnColor($game);
        if (! $turnColor) {
            return false;
        }

        $team = $game->teams()->where('color', $turnColor)->first();

        return $team && $team->captain_user_id === $actor->id;
    }

    public function makePick(Game $game, int $pickedUserId, int $actorUserId): DraftPick
    {
        return DB::transaction(function () use ($game, $pickedUserId, $actorUserId): DraftPick {
            $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actorUserId);

            if ($lockedGame->status !== GameStatus::DRAFTING) {
                throw ValidationException::withMessages(['game' => 'Draft não está ativo.']);
            }

            $turnColor = $this->currentTurnColor($lockedGame);
            if (! $turnColor) {
                throw ValidationException::withMessages(['game' => 'Draft já foi finalizado.']);
            }

            $turnTeam = $lockedGame->teams()->where('color', $turnColor)->first();
            if (! $turnTeam) {
                throw ValidationException::withMessages(['draft' => 'Time do turno atual não foi definido.']);
            }

            $isCaptainTurn = $turnTeam->captain_user_id === $actor->id;
            if ($actor->role !== 'admin' && ! $isCaptainTurn) {
                throw new AuthorizationException('Você não pode escolher neste turno.');
            }

            $isInGame = $lockedGame->players()->where('users.id', $pickedUserId)->exists();
            if (! $isInGame) {
                throw ValidationException::withMessages(['user_id' => 'Jogador não está inscrito nesta partida.']);
            }

            $isCaptain = $lockedGame->teams()->where('captain_user_id', $pickedUserId)->exists();
            if ($isCaptain) {
                throw ValidationException::withMessages(['user_id' => 'Não é permitido escolher capitães.']);
            }

            $alreadyPicked = $lockedGame->draftPicks()->where('picked_user_id', $pickedUserId)->exists();
            if ($alreadyPicked) {
                throw ValidationException::withMessages(['user_id' => 'Jogador já foi escolhido.']);
            }

            $pickIndex = $lockedGame->draftPicks()->count();
            $pick = DraftPick::create([
                'game_id' => $lockedGame->id,
                'round' => intdiv($pickIndex, 3) + 1,
                'pick_in_round' => ($pickIndex % 3) + 1,
                'team_color' => $turnColor,
                'picked_user_id' => $pickedUserId,
                'picked_at' => now(),
            ]);

            $totalAfter = $lockedGame->draftPicks()->count();
            if ($totalAfter >= 12) {
                $lockedGame->update(['status' => GameStatus::DONE]);
            }

            $lockedGame->refresh();
            $lockedGame->loadMissing(['teams.captain', 'draftPicks.pickedUser', 'players']);
            $payload = GamePayload::fromGame($lockedGame, $this);

            rescue(fn () => broadcast(new DraftPickMade($payload))->toOthers(), report: false);
            rescue(fn () => broadcast(new DraftTurnChanged($payload))->toOthers(), report: false);

            if ($lockedGame->status === GameStatus::DONE) {
                rescue(
                    fn () => broadcast(new DraftFinished($payload, $this->buildWhatsAppMessage($lockedGame)))->toOthers(),
                    report: false
                );
            }

            return $pick;
        });
    }

    public function buildWhatsAppMessage(Game $game): string
    {
        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);
        $teams = $this->teamsWithPlayers($game);

        $title = sprintf("Futsal - %s\n", $game->date->format('d/m/Y'));

        return $title
            ."Time Verde:\n- ".implode("\n- ", $teams[TeamColor::GREEN->value])."\n\n"
            ."Time Amarelo:\n- ".implode("\n- ", $teams[TeamColor::YELLOW->value])."\n\n"
            ."Time Azul:\n- ".implode("\n- ", $teams[TeamColor::BLUE->value]);
    }

    public function teamsWithPlayers(Game $game): array
    {
        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);

        $result = [];
        foreach (TeamColor::cases() as $color) {
            $result[$color->value] = [];
        }

        $captains = $game->teams->keyBy(fn ($team) => $team->color->value);
        foreach (TeamColor::cases() as $color) {
            $captainName = $captains->get($color->value)?->captain?->name;
            if ($captainName) {
                $result[$color->value][] = $captainName.' (Capitão)';
            }
        }

        foreach ($game->draftPicks->sortBy('id') as $pick) {
            $result[$pick->team_color->value][] = $pick->pickedUser->name;
        }

        return $result;
    }
}
