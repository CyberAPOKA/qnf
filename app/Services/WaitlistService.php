<?php

namespace App\Services;

use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;

class WaitlistService
{
    /**
     * Promove o próximo jogador da fila de espera para o time do jogador que saiu.
     * Retorna o GamePlayer promovido ou null se a fila estiver vazia.
     */
    public function promoteFromWaitlist(Game $game, int $droppedUserId): ?GamePlayer
    {
        $nextInLine = GamePlayer::where('game_id', $game->id)
            ->whereNotNull('waitlist_at')
            ->where('dropped_out', false)
            ->orderBy('waitlist_at')
            ->first();

        if (! $nextInLine) {
            return null;
        }

        // Find which team the dropped player was on
        $teamColor = $this->findPlayerTeamColor($game, $droppedUserId);

        if (! $teamColor) {
            return null;
        }

        // Remove dropped player from team
        $this->removePlayerFromTeam($game, $droppedUserId, $teamColor);

        // Add waitlisted player to that team
        DraftPick::create([
            'game_id' => $game->id,
            'round' => 99,
            'pick_in_round' => 0,
            'team_color' => $teamColor,
            'picked_user_id' => $nextInLine->user_id,
            'picked_at' => now(),
        ]);

        // Promote: clear waitlist, set joined
        $nextInLine->update([
            'waitlist_at' => null,
            'joined_at' => now(),
        ]);

        return $nextInLine;
    }

    /**
     * Encontra a cor do time do jogador (capitão ou draftado).
     */
    private function findPlayerTeamColor(Game $game, int $userId): ?TeamColor
    {
        $team = Team::where('game_id', $game->id)
            ->where('captain_user_id', $userId)
            ->first();

        if ($team) {
            return $team->color;
        }

        $pick = DraftPick::where('game_id', $game->id)
            ->where('picked_user_id', $userId)
            ->first();

        return $pick?->team_color;
    }

    /**
     * Remove o jogador do time (capitão ou draft pick).
     */
    private function removePlayerFromTeam(Game $game, int $userId, TeamColor $color): void
    {
        $team = Team::where('game_id', $game->id)->where('color', $color)->first();

        if ($team && $team->captain_user_id === $userId) {
            $team->update([
                'captain_user_id' => null,
                'first_pick_user_id' => null,
            ]);
        } else {
            $pick = DraftPick::where('game_id', $game->id)
                ->where('team_color', $color)
                ->where('picked_user_id', $userId)
                ->first();

            if ($pick) {
                if ($team && $team->first_pick_user_id === $userId) {
                    $team->update(['first_pick_user_id' => null]);
                }
                $pick->delete();
            }
        }
    }
}
