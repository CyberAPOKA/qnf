<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Http\Requests\StoreGameScheduleRequest;
use App\Http\Requests\UpdateScheduledGameRequest;
use App\Models\Game;
use App\Services\GameService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminMercadoController extends Controller
{
    public function __construct(
        private readonly GameService $gameService,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->role === 'admin', 403);

        $defaults = $this->gameService->defaultSchedule();
        $pending = $this->gameService->pendingSchedule();
        $currentGame = Game::where('status', '!=', GameStatus::DONE)
            ->orderByDesc('date')
            ->first();

        $schedule = $pending
            ? [
                'round' => $this->gameService->nextRound(),
                'creates_at' => $this->toLocalInput($pending->creates_at),
                'opens_at' => $this->toLocalInput($pending->opens_at),
                'starts_at' => $this->toLocalInput($pending->starts_at),
            ]
            : [
                'round' => $defaults['round'],
                'creates_at' => $this->toLocalInput($defaults['creates_at']),
                'opens_at' => $this->toLocalInput($defaults['opens_at']),
                'starts_at' => $this->toLocalInput($defaults['starts_at']),
            ];

        return Inertia::render('AdminMercado', [
            'schedule' => $schedule,
            'has_pending' => $pending !== null,
            'current_game' => $currentGame ? [
                'id' => $currentGame->id,
                'round' => $currentGame->round,
                'status' => $currentGame->status->value,
                'status_label' => $currentGame->status->label(),
                'opens_at' => $this->toLocalInput($currentGame->opens_at),
                'starts_at' => $this->toLocalInput(
                    $currentGame->starts_at
                        ?? CarbonImmutable::parse($currentGame->date->toDateString(), GameService::TZ)
                            ->setTime(GameService::GAME_HOUR, 0)
                ),
                'can_edit' => in_array($currentGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN], true),
            ] : null,
        ]);
    }

    public function store(StoreGameScheduleRequest $request): RedirectResponse
    {
        $this->gameService->saveSchedule($request->schedulePayload(), $request->user());

        return back()->with('success', 'Agendamento da próxima rodada salvo.');
    }

    public function createGame(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        if (Game::where('status', '!=', GameStatus::DONE)->exists()) {
            return back()->withErrors([
                'schedule' => 'Já existe um jogo em andamento. Finalize-o antes de criar o próximo.',
            ]);
        }

        $game = $this->gameService->createScheduledGameIfNeeded($request->user(), force: true);

        if (! $game) {
            return back()->withErrors([
                'schedule' => 'Não há agendamento pendente para criar o jogo agora.',
            ]);
        }

        return back()->with('success', "Jogo da rodada {$game->round} criado.");
    }

    public function updateGame(UpdateScheduledGameRequest $request, Game $game): RedirectResponse
    {
        abort_unless(in_array($game->status, [GameStatus::SCHEDULED, GameStatus::OPEN], true), 403);

        $opensAt = CarbonImmutable::parse($request->validated('opens_at'), GameService::TZ);
        $startsAt = CarbonImmutable::parse($request->validated('starts_at'), GameService::TZ);

        $game->update([
            'opens_at' => $opensAt,
            'starts_at' => $startsAt,
            'date' => $startsAt->toDateString(),
        ]);

        return back()->with('success', 'Horários do jogo atualizados.');
    }

    private function toLocalInput(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)
            ->timezone(GameService::TZ)
            ->format('Y-m-d\TH:i');
    }
}
