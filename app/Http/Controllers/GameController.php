<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Events\GamePlayerJoined;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\DraftService;
use App\Services\GameService;
use App\Services\ScoringService;
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
        private readonly ScoringService $scoringService,
    ) {}

    public function index(Request $request): Response
    {
        $this->gameService->openGameIfNeeded();

        $game = $this->gameService->getOrCreateThisWeekGame();
        $payload = GamePayload::fromGame($game, $this->draftService, $this->scoringService);

        $isAdmin = $request->user()->role === 'admin';

        $ranking = $this->scoringService->getRanking(includeGuests: true);

        if ($isAdmin) {
            return Inertia::render('AdminDashboard', [
                'game' => $payload,
                'current_user_id' => $request->user()->id,
                'all_users' => User::select('id', 'name', 'position', 'guest')
                    ->where('role', '!=', 'admin')
                    ->orderBy('name')
                    ->get()
                    ->map(fn ($user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'position' => $user->position->value,
                        'position_label' => $user->position->label(),
                        'guest' => $user->guest,
                    ]),
                'can_enter_scores' => $this->scoringService->canEnterScores($game),
                'ranking' => $ranking,
            ]);
        }

        return Inertia::render('PlayerDashboard', [
            'game' => $payload,
            'current_user_id' => $request->user()->id,
            'is_goalkeeper' => $request->user()->position === Position::GOALKEEPER,
            'ranking' => $ranking,
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

                if ($request->user()->position === Position::GOALKEEPER) {
                    throw ValidationException::withMessages(['join' => 'Goleiros são adicionados pelo administrador.']);
                }

                $alreadyJoined = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('user_id', $request->user()->id)
                    ->exists();

                if ($alreadyJoined) {
                    return;
                }

                $linePlayerCount = GamePlayer::where('game_id', $lockedGame->id)
                    ->whereHas('user', fn ($q) => $q->where('position', '!=', Position::GOALKEEPER))
                    ->count();

                if ($linePlayerCount >= 12) {
                    throw ValidationException::withMessages(['join' => 'As vagas para jogadores de linha estão esgotadas.']);
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

        if ($freshGame->status === GameStatus::FULL) {
            $this->gameService->handleGameBecameFull($freshGame, $this->draftService);
        } else {
            $payload = GamePayload::fromGame($freshGame, $this->draftService);
            rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);
        }

        return back();
    }
}
