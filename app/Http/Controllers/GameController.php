<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\CaptainsImageService;
use App\Services\DraftService;
use App\Services\GameParticipationService;
use App\Services\GamePredictionService;
use App\Services\GameService;
use App\Services\LineupsImageService;
use App\Services\PaymentService;
use App\Services\RankingImageService;
use App\Services\RoundWinsRankingService;
use App\Services\ScoringService;
use App\Services\WeekTeamImageService;
use App\Services\WeekTeamMusicService;
use App\Support\GamePayload;
use App\Support\PublicStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly DraftService $draftService,
        private readonly ScoringService $scoringService,
        private readonly GameParticipationService $participationService,
        private readonly PaymentService $paymentService,
        private readonly RoundWinsRankingService $roundWinsRankingService,
        private readonly GamePredictionService $predictionService,
    ) {}

    public function index(Request $request): Response
    {
        $this->gameService->openGameIfNeeded();

        $game = $this->gameService->getOrCreateThisWeekGame();
        $payload = GamePayload::fromGame($game, $this->draftService, $this->scoringService);

        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $ranking = $this->scoringService->getRanking(includeGuests: true);
        $prediction = $this->predictionService->predict($game);
        $weekTeams = $this->getWeekTeams();

        $rounds = Game::orderByDesc('round')
            ->pluck('round')
            ->unique()
            ->values()
            ->all();

        $playerRecord = GamePlayer::where('game_id', $game->id)
            ->where('user_id', $user->id)
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

        $payment = $this->paymentService->getPlayerPayment($user->id, $game->id);

        $props = [
            'game' => $payload,
            'current_user_id' => $user->id,
            'is_admin' => $isAdmin,
            'is_goalkeeper' => $user->position === Position::GOALKEEPER,
            'dropped_out' => $droppedOut,
            'waitlist_position' => $waitlistPosition,
            'ranking' => $ranking,
            'wins_ranking' => $this->roundWinsRankingService->getRanking(includeGuests: true),
            'prediction' => $prediction,
            'week_teams' => $weekTeams,
            'rounds' => $rounds,
            'payment' => $this->paymentService->playerPayload($payment),
        ];

        if ($isAdmin) {
            $props['all_users'] = User::select('id', 'name', 'position', 'guest')
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
                ]);
            $props['can_enter_scores'] = $this->scoringService->canEnterScores($game);
            $props['payments'] = $this->paymentService->getGamePayments($game->id);
        }

        return Inertia::render('Dashboard', $props);
    }

    public function join(Request $request, Game $game): RedirectResponse
    {
        try {
            $this->participationService->join($game, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }

    public function quit(Request $request, Game $game): RedirectResponse
    {
        try {
            $this->participationService->quit($game, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }

    public function joinWaitlist(Request $request, Game $game): RedirectResponse
    {
        try {
            $this->participationService->joinWaitlist($game, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }

    public function generateRandomWeekTeam(Request $request, WeekTeamImageService $imageService): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $paths = $imageService->generateRandom();

        $teams = array_map(fn (string $path) => [
            'image' => PublicStorage::url($path),
            'color' => null,
            'music' => ['source' => 'default'],
        ], $paths);

        return response()->json(['teams' => $teams]);
    }

    public function generateCaptainsImage(Request $request, CaptainsImageService $imageService): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $game = $this->gameService->getOrCreateThisWeekGame();
        $path = $imageService->generate($game);

        if (! $path) {
            return response()->json(['error' => 'Jogadores insuficientes para gerar capitães.'], 422);
        }

        return response()->json(['image' => PublicStorage::url($path)]);
    }

    public function generateLineupsImage(Request $request, LineupsImageService $imageService): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $game = $this->gameService->getOrCreateThisWeekGame();

        $teamPlayerIds = $request->input('teams');

        if (! is_array($teamPlayerIds) || count($teamPlayerIds) !== 3) {
            $teamPlayerIds = $this->draftService->buildTeamPlayerIdsForLineups($game);
        }

        $path = $imageService->generate($game, $teamPlayerIds);

        if (! $path) {
            return response()->json(['error' => 'Times ainda não definidos para gerar as escalações.'], 422);
        }

        return response()->json(['image' => PublicStorage::url($path)]);
    }

    public function generateRankingImage(Request $request, RankingImageService $imageService): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $path = $imageService->generate();

        if (! $path) {
            return response()->json(['error' => 'Não há jogadores no ranking para gerar a imagem.'], 422);
        }

        return response()->json(['image' => PublicStorage::url($path)]);
    }

    public function createPayments(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $game = Game::where('status', GameStatus::DRAFTED)
            ->orderByDesc('id')
            ->first();

        if (! $game) {
            return response()->json(['error' => 'Nenhum jogo com status "drafted" encontrado.'], 422);
        }

        $count = $this->paymentService->createPaymentsForGame($game);

        return response()->json(['message' => "{$count} pagamentos criados.", 'count' => $count]);
    }

    public function getRoundData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'round' => ['required', 'integer', 'min:1'],
        ]);

        $round = (int) $validated['round'];

        $game = Game::with('weekTeamMusics')->where('round', $round)->first();

        $payload = $game
            ? GamePayload::fromGame($game, $this->draftService, $this->scoringService)
            : null;

        $ranking = $this->scoringService->getRanking(includeGuests: true, upToRound: $round);
        $winsRanking = $this->roundWinsRankingService->getRanking(includeGuests: true, upToRound: $round);
        $prediction = $game ? $this->predictionService->predict($game) : null;

        $weekTeams = $game?->week_teams ?? [];

        $isAdmin = $request->user()->role === 'admin';

        $data = [
            'game' => $payload,
            'ranking' => $ranking,
            'wins_ranking' => $winsRanking,
            'prediction' => $prediction,
            'week_teams' => $weekTeams,
        ];

        if ($isAdmin && $game) {
            $data['payments'] = $this->paymentService->getGamePayments($game->id);
            $data['can_enter_scores'] = $this->scoringService->canEnterScores($game);
        }

        return response()->json($data);
    }

    public function regenerateWeekTeam(Request $request, Game $game, WeekTeamImageService $imageService, WeekTeamMusicService $musicService): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        if ($game->status !== GameStatus::DONE) {
            return response()->json(['error' => 'O jogo precisa estar finalizado.'], 422);
        }

        $winnerColors = $imageService->getWinnerColors($game);
        $paths = $imageService->generate($game);

        if (empty($paths)) {
            return response()->json(['error' => 'Não foi possível gerar o time da semana.'], 422);
        }

        $musicService->snapshotForGame($game, $winnerColors);
        $game->update(['week_team_images' => $paths]);

        return response()->json([
            'teams' => $game->fresh(['weekTeamMusics'])->week_teams,
        ]);
    }

    public function regenerateAllWeekTeams(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $total = Game::where('status', GameStatus::DONE)->count();

        \App\Jobs\RegenerateAllWeekTeams::dispatch();

        return response()->json([
            'message' => "Geração enfileirada para {$total} rodadas. Processando em segundo plano.",
        ]);
    }

    private function getWeekTeams(): array
    {
        $lastDoneGame = Game::with('weekTeamMusics')
            ->where('status', GameStatus::DONE)
            ->orderByDesc('id')
            ->first();

        if (! $lastDoneGame || empty($lastDoneGame->week_team_images)) {
            return [];
        }

        $nextGameExists = Game::where('id', '>', $lastDoneGame->id)
            ->exists();

        if ($nextGameExists) {
            return [];
        }

        return $lastDoneGame->week_teams;
    }
}
