<?php

namespace App\Support;

use App\Enums\GameStatus;
use App\Enums\TeamColor;
use App\Models\Game;
use App\Services\DraftService;

class GamePayload
{
    public static function fromGame(Game $game, DraftService $draftService): array
    {
        $game->loadMissing([
            'gamePlayers.user',
            'teams.captain',
            'draftPicks.pickedUser',
        ]);

        $teamsByColor = $game->teams->keyBy(fn ($team) => $team->color->value);
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
            ])
            ->all();

        $teamsPayload = [];
        foreach (TeamColor::cases() as $color) {
            $team = $teamsByColor->get($color->value);
            $teamsPayload[$color->value] = [
                'captain' => $team?->captain ? [
                    'id' => $team->captain->id,
                    'name' => $team->captain->name,
                ] : null,
                'players' => self::pickedPlayersByColor($game, $color),
            ];
        }

        $turnColor = $draftService->currentTurnColor($game);

        return [
            'id' => $game->id,
            'date' => optional($game->date)->toDateString(),
            'status' => $game->status->value,
            'status_label' => $game->status->label(),
            'opens_at' => optional($game->opens_at)->toIso8601String(),
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

    private static function pickedPlayersByColor(Game $game, TeamColor $color): array
    {
        return $game->draftPicks
            ->where('team_color', $color)
            ->sortBy('id')
            ->values()
            ->map(fn ($pick) => [
                'id' => $pick->pickedUser->id,
                'name' => $pick->pickedUser->name,
            ])
            ->all();
    }
}
