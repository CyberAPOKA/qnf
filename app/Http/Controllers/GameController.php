<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Events\GamePlayerJoined;
use App\Jobs\CreatePlayerPaymentJob;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\DraftService;
use App\Services\GameService;
use App\Services\PaymentService;
use App\Services\GamePredictionService;
use App\Services\RoundWinsRankingService;
use App\Services\ScoringService;
use App\Services\WaitlistService;
use App\Services\WeekTeamImageService;
use Illuminate\Http\JsonResponse;
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
        private readonly WaitlistService $waitlistService,
        private readonly PaymentService $paymentService,
        private readonly RoundWinsRankingService $roundWinsRankingService,
        private readonly GamePredictionService $predictionService,
    ) {}

    public function index(Request $request): Response
    {
        $this->gameService->openGameIfNeeded();

        $game = $this->gameService->getOrCreateThisWeekGame();
        $payload = GamePayload::fromGame($game, $this->draftService, $this->scoringService);

        $isAdmin = $request->user()->role === 'admin';

        $ranking = $this->scoringService->getRanking(includeGuests: true);
        $prediction = $this->predictionService->predict($game);
        $weekTeamImages = $this->getWeekTeamImages();

        if ($isAdmin) {
            return Inertia::render('AdminDashboard', [
                'game' => $payload,
                'current_user_id' => $request->user()->id,
                'all_users' => User::select('id', 'name', 'position', 'guest')
                    ->where('role', '!=', 'admin')
                    ->where('active', true)
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
                'wins_ranking' => $this->roundWinsRankingService->getRanking(includeGuests: true),
                'payments' => $this->paymentService->getGamePayments($game->id),
                'prediction' => $prediction,
                'week_team_images' => $weekTeamImages,
            ]);
        }

        $playerRecord = GamePlayer::where('game_id', $game->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $droppedOut = $playerRecord?->dropped_out ?? false;

        $waitlistPosition = null;
        if ($playerRecord?->waitlist_at) {
            $waitlistPosition = GamePlayer::where('game_id', $game->id)
                ->whereNotNull('waitlist_at')
                ->where('dropped_out', false)
                ->where('waitlist_at', '<=', $playerRecord->waitlist_at)
                ->count();
        }

        $user = $request->user();

        $payment = $this->paymentService->getPlayerPayment($user->id, $game->id);

        return Inertia::render('PlayerDashboard', [
            'game' => $payload,
            'current_user_id' => $user->id,
            'is_goalkeeper' => $user->position === Position::GOALKEEPER,
            'dropped_out' => $droppedOut,
            'waitlist_position' => $waitlistPosition,
            'ranking' => $ranking,
            'wins_ranking' => $this->roundWinsRankingService->getRanking(includeGuests: true),
            'suspended_until_round' => $user->suspended_until_round,
            'prediction' => $prediction,
            'week_team_images' => $weekTeamImages,
            'payment' => $payment ? [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'pix_payload' => $payment->pix_payload,
                'qr_code_base64' => $payment->qr_code_base64,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'penalty_rounds' => $payment->penalty_rounds,
            ] : null,
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

                if ($request->user()->isSuspended($lockedGame->round)) {
                    throw ValidationException::withMessages(['join' => 'Você está suspenso e não pode se inscrever.']);
                }

                if ($request->user()->position === Position::GOALKEEPER) {
                    throw ValidationException::withMessages(['join' => 'Goleiros são adicionados pelo administrador.']);
                }

                $existing = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('user_id', $request->user()->id)
                    ->first();

                if ($existing) {
                    if ($existing->dropped_out) {
                        throw ValidationException::withMessages(['join' => 'Você desistiu e não pode se inscrever novamente.']);
                    }

                    return;
                }

                $linePlayerCount = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('dropped_out', false)
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

                $countAfter = GamePlayer::where('game_id', $lockedGame->id)->where('dropped_out', false)->count();
                if ($countAfter >= 15) {
                    $lockedGame->update(['status' => GameStatus::FULL]);
                }
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $freshGame = Game::findOrFail($game->id);

        rescue(fn () => CreatePlayerPaymentJob::dispatchSync($freshGame->id, $request->user()->id), report: false);

        if ($freshGame->status === GameStatus::FULL) {
            $this->gameService->handleGameBecameFull($freshGame, $this->draftService);
        } else {
            $payload = GamePayload::fromGame($freshGame, $this->draftService);
            rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);
        }

        return back();
    }

    public function quit(Request $request, Game $game): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $game): void {
                $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedGame->status, [GameStatus::OPEN, GameStatus::FULL, GameStatus::DRAFTED])) {
                    throw ValidationException::withMessages(['quit' => 'Não é possível desistir neste momento.']);
                }

                $gamePlayer = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('user_id', $request->user()->id)
                    ->where('dropped_out', false)
                    ->firstOrFail();

                $gamePlayer->update(['dropped_out' => true, 'waitlist_at' => null]);

                if (in_array($lockedGame->status, [GameStatus::OPEN, GameStatus::FULL])) {
                    $promoted = $this->waitlistService->promoteFromWaitlistBeforeDraft($lockedGame);
                    if (! $promoted && $lockedGame->status === GameStatus::FULL) {
                        $lockedGame->update(['status' => GameStatus::OPEN]);
                    }
                }

                if ($lockedGame->status === GameStatus::DRAFTED) {
                    $this->waitlistService->promoteFromWaitlist($lockedGame, $request->user()->id);
                }
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $freshGame = Game::findOrFail($game->id);

        rescue(fn () => $this->paymentService->cancelPaymentForPlayer($freshGame->id, $request->user()->id), report: false);

        $payload = GamePayload::fromGame($freshGame, $this->draftService);
        rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);

        return back();
    }

    public function joinWaitlist(Request $request, Game $game): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $game): void {
                $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedGame->status, [GameStatus::FULL, GameStatus::DRAFTING, GameStatus::DRAFTED])) {
                    throw ValidationException::withMessages(['waitlist' => 'A fila de espera não está disponível.']);
                }

                if ($request->user()->isSuspended($lockedGame->round)) {
                    throw ValidationException::withMessages(['waitlist' => 'Você está suspenso e não pode entrar na fila.']);
                }

                if ($request->user()->position === Position::GOALKEEPER) {
                    throw ValidationException::withMessages(['waitlist' => 'Goleiros não podem entrar na fila de espera.']);
                }

                $existing = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('user_id', $request->user()->id)
                    ->first();

                if ($existing) {
                    if ($existing->dropped_out) {
                        throw ValidationException::withMessages(['waitlist' => 'Você desistiu e não pode entrar na fila.']);
                    }
                    throw ValidationException::withMessages(['waitlist' => 'Você já está inscrito ou na fila.']);
                }

                GamePlayer::create([
                    'game_id' => $lockedGame->id,
                    'user_id' => $request->user()->id,
                    'waitlist_at' => now(),
                ]);
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }

    public function generateRandomWeekTeam(Request $request, WeekTeamImageService $imageService): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $paths = $imageService->generateRandom();

        $images = array_map(fn ($p) => '/storage/'.$p, $paths);

        return response()->json(['images' => $images]);
    }

    private function getWeekTeamImages(): array
    {
        $lastDoneGame = Game::where('status', GameStatus::DONE)
            ->orderByDesc('id')
            ->first();

        if (! $lastDoneGame || empty($lastDoneGame->week_team_images)) {
            return [];
        }

        return array_map(fn ($p) => '/storage/'.$p, $lastDoneGame->week_team_images);
    }
}
