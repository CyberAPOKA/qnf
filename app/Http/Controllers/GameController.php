<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\GameBecameFull;
use App\Events\GamePlayerJoined;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\DraftService;
use App\Services\GameService;
use App\Support\GamePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly DraftService $draftService,
    ) {}

    public function index(Request $request): Response
    {
        $this->gameService->openGameIfNeeded();

        $game = $this->gameService->getOrCreateThisWeekGame();
        $payload = GamePayload::fromGame($game, $this->draftService);

        $isAdmin = $request->user()->role === 'admin';

        if ($isAdmin) {
            return Inertia::render('AdminDashboard', [
                'game' => $payload,
                'current_user_id' => $request->user()->id,
                'all_users' => User::select('id', 'name', 'position')->orderBy('name')->get()
                    ->map(fn ($user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'position' => $user->position->value,
                        'position_label' => $user->position->label(),
                    ]),
            ]);
        }

        return Inertia::render('PlayerDashboard', [
            'game' => $payload,
            'current_user_id' => $request->user()->id,
        ]);
    }

    public function join(Request $request, Game $game): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $game): void {
                $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

                if ($lockedGame->status !== GameStatus::OPEN) {
                    throw ValidationException::withMessages(['join' => 'A lista não está aberta.']);
                }

                $alreadyJoined = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('user_id', $request->user()->id)
                    ->exists();

                if ($alreadyJoined) {
                    return;
                }

                $count = GamePlayer::where('game_id', $lockedGame->id)->count();
                if ($count >= 15) {
                    throw ValidationException::withMessages(['join' => 'A partida já lotou.']);
                }

                GamePlayer::create([
                    'game_id' => $lockedGame->id,
                    'user_id' => $request->user()->id,
                    'joined_at' => now(),
                ]);

                $countAfter = GamePlayer::where('game_id', $lockedGame->id)->count();
                if ($countAfter >= 15) {
                    $lockedGame->update(['status' => GameStatus::FULL]);
                }
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $freshGame = Game::findOrFail($game->id);
        $payload = GamePayload::fromGame($freshGame, $this->draftService);

        rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);
        if ($freshGame->status === GameStatus::FULL) {
            rescue(fn () => broadcast(new GameBecameFull($freshGame->id, $payload))->toOthers(), report: false);
        }

        return back();
    }
}
