<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\CaptainsDrawn;
use App\Events\GameBecameFull;
use App\Events\GamePlayerJoined;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\DraftService;
use App\Support\GamePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminGameController extends Controller
{
    public function __construct(private readonly DraftService $draftService) {}

    public function addPlayers(Request $request, Game $game): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($request, $game) {
            $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN, GameStatus::FULL])) {
                throw ValidationException::withMessages(['add' => 'A lista não permite mais inscrições.']);
            }

            $existing = GamePlayer::where('game_id', $lockedGame->id)
                ->pluck('user_id')
                ->toArray();

            $newIds = collect($request->input('user_ids'))
                ->diff($existing)
                ->values();

            $slotsLeft = 15 - count($existing);
            $toInsert = $newIds->take($slotsLeft);

            foreach ($toInsert as $userId) {
                GamePlayer::create([
                    'game_id' => $lockedGame->id,
                    'user_id' => $userId,
                    'joined_at' => now(),
                ]);
            }

            $totalAfter = count($existing) + $toInsert->count();
            if ($totalAfter >= 15 && in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN])) {
                $lockedGame->update(['status' => GameStatus::FULL]);
            }
        });

        $freshGame = Game::findOrFail($game->id);
        $payload = GamePayload::fromGame($freshGame, $this->draftService);

        rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);
        if ($freshGame->status === GameStatus::FULL) {
            rescue(fn () => broadcast(new GameBecameFull($freshGame->id, $payload))->toOthers(), report: false);
        }

        return back();
    }

    public function drawCaptains(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('drawCaptains', $game);

        try {
            $this->draftService->drawCaptains($game);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $freshGame = Game::findOrFail($game->id);
        $payload = GamePayload::fromGame($freshGame, $this->draftService);
        rescue(fn () => broadcast(new CaptainsDrawn($freshGame->id, $payload))->toOthers(), report: false);

        return redirect()->route('games.draft', $game);
    }
}
