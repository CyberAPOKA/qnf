<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Events\GamePlayerJoined;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftService;
use App\Services\GameService;
use App\Services\ScoringService;
use App\Support\GamePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminGameController extends Controller
{
    public function __construct(
        private readonly DraftService $draftService,
        private readonly GameService $gameService,
    ) {}

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
                ->where('dropped_out', false)
                ->pluck('user_id')
                ->toArray();

            $newIds = collect($request->input('user_ids'))
                ->diff($existing)
                ->values();

            $slotsLeft = 15 - count($existing);
            $toInsert = $newIds->take($slotsLeft);

            foreach ($toInsert as $userId) {
                GamePlayer::updateOrCreate(
                    ['game_id' => $lockedGame->id, 'user_id' => $userId],
                    ['joined_at' => now(), 'dropped_out' => false],
                );
            }

            $totalAfter = count($existing) + $toInsert->count();
            if ($totalAfter >= 15 && in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN])) {
                $goalkeeperCount = GamePlayer::where('game_id', $lockedGame->id)
                    ->where('dropped_out', false)
                    ->whereHas('user', fn ($q) => $q->where('position', Position::GOALKEEPER))
                    ->count();

                if ($goalkeeperCount < 3) {
                    throw ValidationException::withMessages(['add' => 'São necessários pelo menos 3 goleiros para fechar a lista.']);
                }

                $lockedGame->update(['status' => GameStatus::FULL]);
            }
        });

        $freshGame = Game::findOrFail($game->id);

        if ($freshGame->status === GameStatus::FULL) {
            $this->gameService->handleGameBecameFull($freshGame, $this->draftService);
        } else {
            $payload = GamePayload::fromGame($freshGame, $this->draftService);
            rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);
        }

        return back();
    }

    public function storePlayer(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'position' => ['required', Rule::in(Position::values())],
            'password' => ['required', 'string', 'min:4'],
        ]);

        User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['phone'] . '@player.local',
            'role' => 'player',
            'position' => $validated['position'],
            'guest' => false,
            'password' => Hash::make($validated['password']),
        ]);

        return back();
    }

    public function storeGuest(Request $request, Game $game, ScoringService $scoringService): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', Rule::in(Position::values())],
            'enroll' => ['boolean'],
            'team_color' => ['nullable', Rule::in(TeamColor::values())],
        ]);

        $guest = User::create([
            'name' => $validated['name'],
            'phone' => 'guest-' . Str::random(8),
            'email' => 'guest-' . Str::random(8) . '@guest.local',
            'role' => 'player',
            'position' => $validated['position'],
            'guest' => true,
            'password' => Hash::make(Str::random(16)),
        ]);

        $teamColor = $validated['team_color'] ?? null;

        if ($teamColor) {
            DB::transaction(function () use ($game, $guest, $teamColor) {
                $team = Team::where('game_id', $game->id)->where('color', $teamColor)->first();

                if ($team && ! $team->captain_user_id) {
                    $team->update(['captain_user_id' => $guest->id]);
                } else {
                    DraftPick::create([
                        'game_id' => $game->id,
                        'round' => 99,
                        'pick_in_round' => 0,
                        'team_color' => $teamColor,
                        'picked_user_id' => $guest->id,
                        'picked_at' => now(),
                    ]);
                }

                GamePlayer::firstOrCreate(
                    ['game_id' => $game->id, 'user_id' => $guest->id],
                    ['joined_at' => now()]
                );
            });

            $hasScores = Team::where('game_id', $game->id)->whereNotNull('score')->exists();
            if ($hasScores) {
                $scoringService->calculateAndAssignPoints($game);
            }
        } elseif ($validated['enroll'] ?? false) {
            DB::transaction(function () use ($game, $guest) {
                $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN, GameStatus::FULL])) {
                    throw ValidationException::withMessages(['guest' => 'A lista não permite mais inscrições.']);
                }

                $count = GamePlayer::where('game_id', $lockedGame->id)->where('dropped_out', false)->count();
                if ($count >= 15) {
                    throw ValidationException::withMessages(['guest' => 'A lista já está cheia.']);
                }

                GamePlayer::create([
                    'game_id' => $lockedGame->id,
                    'user_id' => $guest->id,
                    'joined_at' => now(),
                ]);

                $countAfter = $count + 1;
                if ($countAfter >= 15 && in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN])) {
                    $lockedGame->update(['status' => GameStatus::FULL]);
                }
            });

            $freshGame = Game::findOrFail($game->id);

            if ($freshGame->status === GameStatus::FULL) {
                $this->gameService->handleGameBecameFull($freshGame, $this->draftService);
            } else {
                $payload = GamePayload::fromGame($freshGame, $this->draftService);
                rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);
            }
        }

        return back();
    }

    public function removeFromTeam(Request $request, Game $game, ScoringService $scoringService): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'color' => ['required', Rule::in(TeamColor::values())],
        ]);

        $userId = $validated['user_id'];
        $color = $validated['color'];

        DB::transaction(function () use ($game, $userId, $color) {
            $team = Team::where('game_id', $game->id)->where('color', $color)->first();

            if ($team && $team->captain_user_id === $userId) {
                $team->update([
                    'captain_user_id' => null,
                    'first_pick_user_id' => null,
                ]);
            } else {
                $pick = DraftPick::where('game_id', $game->id)
                    ->where('team_color', $color)
                    ->where('picked_user_id', $userId)
                    ->first();

                if ($pick) {
                    if ($team && $team->first_pick_user_id === $userId) {
                        $team->update(['first_pick_user_id' => null]);
                    }
                    $pick->delete();
                }
            }
        });

        $hasScores = Team::where('game_id', $game->id)->whereNotNull('score')->exists();
        if ($hasScores) {
            $scoringService->calculateAndAssignPoints($game);
        }

        return back();
    }

    public function addToTeam(Request $request, Game $game, ScoringService $scoringService): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'color' => ['required', Rule::in(TeamColor::values())],
        ]);

        $userId = $validated['user_id'];
        $color = $validated['color'];

        $player = User::findOrFail($userId);

        DB::transaction(function () use ($game, $userId, $color, $player) {
            $isCaptain = Team::where('game_id', $game->id)->where('captain_user_id', $userId)->exists();
            $isDrafted = DraftPick::where('game_id', $game->id)->where('picked_user_id', $userId)->exists();

            if ($isCaptain || $isDrafted) {
                throw ValidationException::withMessages(['user_id' => 'Jogador já está em um time.']);
            }

            $team = Team::where('game_id', $game->id)->where('color', $color)->first();

            // Count current team composition
            $hasCaptain = $team && $team->captain_user_id;
            $captainIsGoalkeeper = $hasCaptain
                ? User::where('id', $team->captain_user_id)->where('position', Position::GOALKEEPER)->exists()
                : false;

            $draftedGoalkeepers = DraftPick::where('game_id', $game->id)
                ->where('team_color', $color)
                ->whereHas('pickedUser', fn ($q) => $q->where('position', Position::GOALKEEPER))
                ->count();

            $draftedLinePlayers = DraftPick::where('game_id', $game->id)
                ->where('team_color', $color)
                ->whereHas('pickedUser', fn ($q) => $q->where('position', '!=', Position::GOALKEEPER))
                ->count();

            $goalkeeperCount = $draftedGoalkeepers + ($captainIsGoalkeeper ? 1 : 0);
            $lineCount = $draftedLinePlayers + ($hasCaptain && ! $captainIsGoalkeeper ? 1 : 0);
            $totalCount = ($hasCaptain ? 1 : 0) + $draftedGoalkeepers + $draftedLinePlayers;

            if ($totalCount >= 5) {
                throw ValidationException::withMessages(['user_id' => 'O time já está completo (5 jogadores).']);
            }

            $isGoalkeeper = $player->position === Position::GOALKEEPER;

            if ($isGoalkeeper && $goalkeeperCount >= 1) {
                throw ValidationException::withMessages(['user_id' => 'O time já tem 1 goleiro.']);
            }

            if (! $isGoalkeeper && $lineCount >= 4) {
                throw ValidationException::withMessages(['user_id' => 'O time já tem 4 jogadores de linha.']);
            }

            if ($team && ! $team->captain_user_id) {
                $team->update(['captain_user_id' => $userId]);
            } else {
                DraftPick::create([
                    'game_id' => $game->id,
                    'round' => 99,
                    'pick_in_round' => 0,
                    'team_color' => $color,
                    'picked_user_id' => $userId,
                    'picked_at' => now(),
                ]);
            }

            GamePlayer::firstOrCreate(
                ['game_id' => $game->id, 'user_id' => $userId],
                ['joined_at' => now()]
            );
        });

        $hasScores = Team::where('game_id', $game->id)->whereNotNull('score')->exists();
        if ($hasScores) {
            $scoringService->calculateAndAssignPoints($game);
        }

        return back();
    }

    public function removePlayer(Request $request, Game $game): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($game, $validated): void {
            $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedGame->status, [GameStatus::OPEN, GameStatus::FULL])) {
                throw ValidationException::withMessages(['remove' => 'Não é possível remover jogadores neste momento.']);
            }

            $gamePlayer = GamePlayer::where('game_id', $lockedGame->id)
                ->where('user_id', $validated['user_id'])
                ->where('dropped_out', false)
                ->firstOrFail();

            $gamePlayer->update(['dropped_out' => true]);

            if ($lockedGame->status === GameStatus::FULL) {
                $lockedGame->update(['status' => GameStatus::OPEN]);
            }
        });

        $freshGame = Game::findOrFail($game->id);
        $payload = GamePayload::fromGame($freshGame, $this->draftService);
        rescue(fn () => broadcast(new GamePlayerJoined($freshGame->id, $payload))->toOthers(), report: false);

        return back();
    }

    public function saveScores(Request $request, Game $game, ScoringService $scoringService): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'scores' => ['required', 'array', 'size:3'],
            'scores.green' => ['required', 'integer', 'min:0', 'max:99'],
            'scores.yellow' => ['required', 'integer', 'min:0', 'max:99'],
            'scores.blue' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        try {
            $scoringService->saveScores($game, $validated['scores'], force: true);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }
}
