<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\DraftService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoundsController extends Controller
{
    public function __construct(
        private readonly DraftService $draftService,
    ) {}

    public function index(Request $request): Response
    {
        $rounds = Game::query()
            ->whereIn('status', [GameStatus::DRAFTED->value, GameStatus::DONE->value])
            ->orderByDesc('round')
            ->get()
            ->map(fn (Game $game) => [
                'round' => $game->round,
                'date' => $game->date?->format('Y-m-d'),
                'status' => $game->status->value,
                'status_label' => $game->status->label(),
                'teams' => $this->draftService->teamsWithPlayers($game),
            ])
            ->values()
            ->all();

        return Inertia::render('Rounds', [
            'rounds' => $rounds,
            'current_user_id' => $request->user()->id,
        ]);
    }
}
