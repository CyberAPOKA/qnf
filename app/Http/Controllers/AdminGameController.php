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
                $goalkeeperCount = GamePlayer::where('game_id', $lockedGame->id)
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

    public function storeGuest(Request $request, Game $game): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', Rule::in(Position::values())],
            'enroll' => ['boolean'],
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

        if ($validated['enroll'] ?? false) {
            DB::transaction(function () use ($game, $guest) {
                $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedGame->status, [GameStatus::SCHEDULED, GameStatus::OPEN, GameStatus::FULL])) {
                    throw ValidationException::withMessages(['guest' => 'A lista não permite mais inscrições.']);
                }

                $count = GamePlayer::where('game_id', $lockedGame->id)->count();
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
}
