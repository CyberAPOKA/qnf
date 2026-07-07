<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\ScoringService;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    public function __construct(
        private readonly ScoringService $scoringService,
    ) {}

    public function index(): Response
    {
        $rounds = Game::query()
            ->where('status', GameStatus::DONE->value)
            ->orderBy('round')
            ->get(['round', 'date'])
            ->unique('round')
            ->values();

        $snapshots = $rounds->map(function ($game) {
            $ranking = collect($this->scoringService->getRanking(
                limit: 100,
                includeGuests: true,
                upToRound: $game->round,
            ))
                ->filter(fn (array $player) => $player['position'] !== 'goalkeeper')
                ->take(10)
                ->values()
                ->all();

            return [
                'round' => $game->round,
                'date' => $game->date?->format('Y-m-d'),
                'ranking' => $ranking,
            ];
        })->all();

        return Inertia::render('Timeline', [
            'snapshots' => $snapshots,
        ]);
    }
}
