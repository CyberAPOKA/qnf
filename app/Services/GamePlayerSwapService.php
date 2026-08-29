<?php

namespace App\Services;

use App\Enums\Position;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameWeekTeamMusic;
use App\Models\Payment;
use App\Models\PlayerCard;
use App\Models\PlayerCardCycle;
use App\Models\RecClip;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GamePlayerSwapService
{
    /**
     * Troca todas as relações dos dois jogadores nesta rodada (jogo).
     */
    public function swap(Game $game, int $fromUserId, int $toUserId): void
    {
        if ($fromUserId === $toUserId) {
            throw ValidationException::withMessages([
                'replacement_user_id' => 'Selecione um jogador diferente.',
            ]);
        }

        DB::transaction(function () use ($game, $fromUserId, $toUserId): void {
            $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

            $fromPlayer = GamePlayer::where('game_id', $lockedGame->id)
                ->where('user_id', $fromUserId)
                ->where('dropped_out', false)
                ->first();

            if (! $fromPlayer) {
                throw ValidationException::withMessages([
                    'user_id' => 'Este jogador não está inscrito nesta rodada.',
                ]);
            }

            $alreadyInList = GamePlayer::where('game_id', $lockedGame->id)
                ->where('user_id', $toUserId)
                ->where('dropped_out', false)
                ->whereNull('waitlist_at')
                ->exists();

            if ($alreadyInList) {
                throw ValidationException::withMessages([
                    'replacement_user_id' => 'Este jogador já está na lista desta rodada.',
                ]);
            }

            $fromUser = User::findOrFail($fromUserId);
            $toUser = User::findOrFail($toUserId);
            $fromIsGoalkeeper = $fromUser->position === Position::GOALKEEPER;
            $toIsGoalkeeper = $toUser->position === Position::GOALKEEPER;

            if ($fromIsGoalkeeper !== $toIsGoalkeeper) {
                throw ValidationException::withMessages([
                    'replacement_user_id' => $fromIsGoalkeeper
                        ? 'Selecione um goleiro para substituir o goleiro.'
                        : 'Selecione um jogador de linha para substituir.',
                ]);
            }

            $this->swapUniqueUserRows(GamePlayer::class, ['game_id' => $lockedGame->id], 'user_id', $fromUserId, $toUserId);
            $this->swapUniqueUserRows(Payment::class, ['game_id' => $lockedGame->id], 'user_id', $fromUserId, $toUserId);
            $this->swapUniqueUserRows(DraftPick::class, ['game_id' => $lockedGame->id], 'picked_user_id', $fromUserId, $toUserId);
            $this->swapTeamUserIds($lockedGame->id, $fromUserId, $toUserId);
            $this->swapColumnByIds(
                GameWeekTeamMusic::query()->where('game_id', $lockedGame->id),
                'captain_user_id',
                $fromUserId,
                $toUserId,
            );
            $this->swapColumnByIds(
                RecClip::query()->where('game_id', $lockedGame->id),
                'user_id',
                $fromUserId,
                $toUserId,
            );

            if ($lockedGame->round !== null) {
                $this->swapColumnByIds(
                    PlayerCard::withTrashed()->where('round', $lockedGame->round),
                    'user_id',
                    $fromUserId,
                    $toUserId,
                );
                $this->swapColumnByIds(
                    PlayerCardCycle::withTrashed()->where('infraction_round', $lockedGame->round),
                    'user_id',
                    $fromUserId,
                    $toUserId,
                );
            }
        });
    }

    /**
     * Modelos 1:1 com unique (game_id, user_id): troca o estado do slot
     * quando os dois existem, ou transfere o user_id quando só um existe.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $scope
     */
    private function swapUniqueUserRows(string $modelClass, array $scope, string $userColumn, int $userA, int $userB): void
    {
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        foreach ($scope as $column => $value) {
            $query->where($column, $value);
        }

        $rowA = (clone $query)->where($userColumn, $userA)->first();
        $rowB = (clone $query)->where($userColumn, $userB)->first();

        if (! $rowA && ! $rowB) {
            return;
        }

        if ($rowA && $rowB) {
            $this->exchangeAttributes($rowA, $rowB, [$userColumn, ...array_keys($scope)]);
            $this->exchangeTrashedState($rowA, $rowB);

            return;
        }

        ($rowA ?? $rowB)->update([
            $userColumn => $rowA ? $userB : $userA,
        ]);
    }

    /**
     * @param  list<string>  $except
     */
    private function exchangeAttributes(Model $left, Model $right, array $except): void
    {
        $skip = array_merge(['id', 'created_at', 'updated_at', 'deleted_at'], $except);

        $leftData = collect($left->getAttributes())->except($skip)->all();
        $rightData = collect($right->getAttributes())->except($skip)->all();

        $left->update($rightData);
        $right->update($leftData);
    }

    private function exchangeTrashedState(Model $left, Model $right): void
    {
        if (! method_exists($left, 'trashed') || ! method_exists($right, 'trashed')) {
            return;
        }

        $leftTrashed = $left->trashed();
        $rightTrashed = $right->trashed();

        if ($leftTrashed === $rightTrashed) {
            return;
        }

        if ($leftTrashed) {
            $left->restore();
            $right->delete();
        } else {
            $right->restore();
            $left->delete();
        }
    }

    private function swapTeamUserIds(int $gameId, int $userA, int $userB): void
    {
        Team::where('game_id', $gameId)
            ->where(function ($query) use ($userA, $userB) {
                $query->whereIn('captain_user_id', [$userA, $userB])
                    ->orWhereIn('first_pick_user_id', [$userA, $userB]);
            })
            ->get()
            ->each(function (Team $team) use ($userA, $userB): void {
                $updates = [];

                if ((int) $team->captain_user_id === $userA) {
                    $updates['captain_user_id'] = $userB;
                } elseif ((int) $team->captain_user_id === $userB) {
                    $updates['captain_user_id'] = $userA;
                }

                if ((int) $team->first_pick_user_id === $userA) {
                    $updates['first_pick_user_id'] = $userB;
                } elseif ((int) $team->first_pick_user_id === $userB) {
                    $updates['first_pick_user_id'] = $userA;
                }

                if ($updates !== []) {
                    $team->update($updates);
                }
            });
    }

    private function swapColumnByIds(Builder $query, string $column, int $userA, int $userB): void
    {
        $rowsA = (clone $query)->where($column, $userA)->get();
        $rowsB = (clone $query)->where($column, $userB)->get();

        foreach ($rowsA as $row) {
            $row->update([$column => $userB]);
        }

        foreach ($rowsB as $row) {
            $row->update([$column => $userA]);
        }
    }
}
