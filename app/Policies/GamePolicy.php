<?php

namespace App\Policies;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use App\Services\DraftService;

class GamePolicy
{
    public function pick(User $user, Game $game): bool
    {
        if ($game->status !== GameStatus::DRAFTING) {
            return false;
        }

        $pickCount = $game->draftPicks()->count();
        if ($pickCount >= 12) {
            return false;
        }

        $turnColor = DraftService::SNAKE_SEQUENCE[$pickCount];
        $team = $game->teams()->where('color', $turnColor)->first();

        return $team && $team->captain_user_id === $user->id;
    }
}
