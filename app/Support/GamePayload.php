<?php

namespace App\Support;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\DraftService;

class GamePayload
{
    public static function fromGame(Game $game, DraftService $draftService): array
    {
        $game->loadMissing([
            'gamePlayers.user',
            'teams.captain',
            'teams.firstPick',
            'draftPicks.pickedUser',
        ]);

        $captainIds = $game->teams->pluck('captain_user_id')->filter()->values();
        $pickedIds = $game->draftPicks->pluck('picked_user_id')->values();

        $availablePlayers = $game->gamePlayers
            ->map(fn ($item) => $item->user)
            ->filter()
            ->reject(fn ($user) => $captainIds->contains($user->id) || $pickedIds->contains($user->id))
            ->values()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'position' => $user->position->value,
                'position_label' => $user->position->label(),
                'guest' => $user->guest,
            ])
            ->all();

        $teamsPayload = $draftService->teamsWithPlayers($game);
        $turnColor = $draftService->currentTurnColor($game);

        return [
            'id' => $game->id,
            'date' => optional($game->date)->toDateString(),
            'status' => $game->status->value,
            'status_label' => $game->status->label(),
            'opens_at' => optional($game->opens_at)->toIso8601String(),
            'closes_at' => optional($game->closes_at)->toIso8601String(),
            'players_count' => $game->gamePlayers->count(),
            'players' => $game->gamePlayers
                ->sortBy('joined_at')
                ->values()
                ->map(fn ($entry) => [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                    'phone' => $entry->user->phone,
                    'position' => $entry->user->position->value,
                    'position_label' => $entry->user->position->label(),
                    'guest' => $entry->user->guest,
                    'joined_at' => optional($entry->joined_at)->toIso8601String(),
                ])
                ->all(),
            'teams' => $teamsPayload,
            'picks' => $game->draftPicks
                ->sortBy('id')
                ->values()
                ->map(fn ($pick) => [
                    'id' => $pick->id,
                    'round' => $pick->round,
                    'pick_in_round' => $pick->pick_in_round,
                    'team_color' => $pick->team_color->value,
                    'picked_user' => [
                        'id' => $pick->pickedUser->id,
                        'name' => $pick->pickedUser->name,
                    ],
                    'picked_at' => optional($pick->picked_at)->toIso8601String(),
                ])
                ->all(),
            'turn_color' => $turnColor?->value,
            'available_players' => $availablePlayers,
            'whatsapp_message' => $game->status === GameStatus::DONE ? $draftService->buildWhatsAppMessage($game) : null,
        ];
    }

}
