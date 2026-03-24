<?php

namespace App\Support;

use App\Enums\GameStatus;
use App\Http\Resources\DraftPickResource;
use App\Http\Resources\GamePlayerResource;
use App\Http\Resources\PlayerResource;
use App\Models\Game;
use App\Services\DraftService;
use App\Services\ScoringService;

class GamePayload
{
    public static function fromGame(Game $game, DraftService $draftService, ?ScoringService $scoringService = null): array
    {
        $game->loadMissing([
            'gamePlayers.user',
            'teams.captain',
            'teams.firstPick',
            'draftPicks.pickedUser',
        ]);

        $activePlayers = $game->gamePlayers->reject(fn ($gp) => $gp->dropped_out || $gp->waitlist_at);

        $captainIds = $game->teams->pluck('captain_user_id')->filter()->values();
        $pickedIds = $game->draftPicks->pluck('picked_user_id')->values();

        $availableUsers = $activePlayers
            ->map(fn ($item) => $item->user)
            ->filter()
            ->reject(fn ($user) => $captainIds->contains($user->id) || $pickedIds->contains($user->id))
            ->values();

        $statsMap = collect();
        $rankMap = collect();
        $lastResultsMap = [];
        $winStreaksMap = [];

        if ($scoringService !== null) {
            $availableIds = $availableUsers->pluck('id')->all();
            if (! empty($availableIds)) {
                $statsMap = $scoringService->getPlayerStats(userIds: $availableIds, includeGuests: true);
            }

            $rankMap = collect($scoringService->getRanking(limit: 999, includeGuests: true))
                ->pluck('rank', 'id');

            $lastResultsMap = $scoringService->getLastResults();
            $winStreaksMap = $scoringService->getWinStreaks();
        }

        $availablePlayers = $availableUsers
            ->map(function ($user) use ($statsMap, $rankMap, $lastResultsMap, $winStreaksMap) {
                $data = (new PlayerResource($user))->withStats($statsMap->get($user->id))->resolve();
                $data['rank'] = $rankMap->get($user->id);
                $data['last_results'] = $lastResultsMap[$user->id] ?? [];
                $data['win_streak'] = $winStreaksMap[$user->id] ?? 0;

                return $data;
            })
            ->sortBy([
                fn ($a, $b) => ($a['position'] === 'goalkeeper') <=> ($b['position'] === 'goalkeeper'),
                ['total_points', 'desc'],
                ['games_played', 'asc'],
            ])
            ->values()
            ->all();

        $teamsPayload = $draftService->teamsWithPlayers($game);
        $turnColor = $draftService->currentTurnColor($game);

        $totalPicks = $game->draftPicks->count();
        $isDoublePick = $totalPicks < 11
            && isset(DraftService::SNAKE_SEQUENCE[$totalPicks + 1])
            && $turnColor === DraftService::SNAKE_SEQUENCE[$totalPicks + 1];

        return [
            'id' => $game->id,
            'date' => optional($game->date)->toDateString(),
            'round' => $game->round,
            'status' => $game->status->value,
            'status_label' => $game->status->label(),
            'opens_at' => optional($game->opens_at)->toIso8601String(),
            'closes_at' => optional($game->closes_at)->toIso8601String(),
            'players_count' => $activePlayers->count(),
            'players' => GamePlayerResource::collection($activePlayers->sortBy('joined_at')->values())->resolve(),
            'teams' => $teamsPayload,
            'picks' => DraftPickResource::collection($game->draftPicks->sortBy('id')->values())->resolve(),
            'turn_color' => $turnColor?->value,
            'is_double_pick' => $isDoublePick,
            'available_players' => $availablePlayers,
            'whatsapp_message' => in_array($game->status, [GameStatus::DRAFTED, GameStatus::DONE]) ? $draftService->buildWhatsAppMessage($game) : null,
        ];
    }
}
