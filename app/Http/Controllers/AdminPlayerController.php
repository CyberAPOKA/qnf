<?php

namespace App\Http\Controllers;

use App\Enums\CardType;
use App\Enums\GameStatus;
use App\Http\Requests\ConvertGuestRequest;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\PlayerCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPlayerController extends Controller
{
    public function __construct(
        private readonly PlayerCardService $playerCardService,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->role === 'admin', 403);

        $currentRound = $this->playerCardService->currentRound();

        $roundsPlayed = GamePlayer::query()
            ->join('games', 'game_players.game_id', '=', 'games.id')
            ->where('games.status', GameStatus::DONE->value)
            ->select('game_players.user_id', DB::raw('COUNT(DISTINCT games.id) as rounds_played'))
            ->groupBy('game_players.user_id')
            ->pluck('rounds_played', 'user_id');

        $players = User::query()
            ->where('role', '!=', 'admin')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($currentRound, $roundsPlayed) {
                $displayCards = $this->playerCardService->getDisplayCards($user, $currentRound);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'position' => $user->position->value,
                    'position_label' => $user->position->label(),
                    'guest' => $user->guest,
                    'ability' => $user->ability,
                    'active' => $user->active,
                    'suspended_until_round' => $user->suspended_until_round,
                    'photo_front' => $user->photo_front_url,
                    'photo_side' => $user->photo_side_url,
                    'rounds_played' => (int) ($roundsPlayed[$user->id] ?? 0),
                    'display_cards' => $displayCards,
                    'cards_count' => count($displayCards),
                    'card_history' => $this->playerCardService->getCardHistory($user),
                ];
            });

        $doneGames = Game::where('status', GameStatus::DONE)
            ->orderByDesc('round')
            ->get(['id', 'round']);

        return Inertia::render('AdminPlayers', [
            'players' => $players,
            'done_games' => $doneGames,
            'current_round' => $currentRound,
        ]);
    }

    public function store(StorePlayerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $data = [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['phone'].'@player.local',
            'role' => 'player',
            'position' => $validated['position'],
            'guest' => false,
            'active' => $validated['active'] ?? true,
            'password' => Hash::make($validated['password']),
        ];

        if ($path = $this->storePhoto($request->file('photo_front'))) {
            $data['photo_front'] = $path;
        }

        if ($path = $this->storePhoto($request->file('photo_side'))) {
            $data['photo_side'] = $path;
        }

        User::create($data);

        return back();
    }

    public function update(UpdatePlayerRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['phone'].'@player.local',
            'position' => $validated['position'],
            'ability' => $validated['ability'] ?? $user->ability,
            'active' => $validated['active'] ?? true,
        ]);

        if ($path = $this->storePhoto($request->file('photo_front'))) {
            if ($user->photo_front) {
                Storage::disk('public')->delete($user->photo_front);
            }

            $user->photo_front = $path;
        }

        if ($path = $this->storePhoto($request->file('photo_side'))) {
            if ($user->photo_side) {
                Storage::disk('public')->delete($user->photo_side);
            }

            $user->photo_side = $path;
        }

        $user->save();

        return back();
    }

    public function convertGuest(ConvertGuestRequest $request, User $user): RedirectResponse
    {
        abort_unless($user->guest, 404);

        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['phone'].'@player.local',
            'position' => $validated['position'],
            'guest' => false,
            'password' => Hash::make('qnf'),
        ]);

        if ($path = $this->storePhoto($request->file('photo_front'))) {
            if ($user->photo_front) {
                Storage::disk('public')->delete($user->photo_front);
            }

            $user->photo_front = $path;
        }

        if ($path = $this->storePhoto($request->file('photo_side'))) {
            if ($user->photo_side) {
                Storage::disk('public')->delete($user->photo_side);
            }

            $user->photo_side = $path;
        }

        $user->save();

        return back();
    }

    public function storeCard(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['yellow', 'red'])],
            'round' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->playerCardService->addCard(
                $user,
                CardType::from($validated['type']),
                (int) $validated['round'],
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'round' => ['required', 'integer', 'min:1'],
            'duration' => ['required', 'in:1,2,3,permanent'],
        ]);

        if ($validated['duration'] === 'permanent') {
            $user->update(['suspended_until_round' => 0]);
        } else {
            $suspendedUntil = $validated['round'] + (int) $validated['duration'] + 1;
            $user->update(['suspended_until_round' => $suspendedUntil]);
        }

        return back();
    }

    public function unsuspend(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $user->update(['suspended_until_round' => null]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($user->role === 'admin', 403);

        DB::transaction(function () use ($user) {
            $user->gamePlayers()->delete();
            $user->playerCards()->delete();
            $user->playerCardCycles()->delete();
            Payment::where('user_id', $user->id)->delete();
            DraftPick::where('picked_user_id', $user->id)->delete();

            Team::where('captain_user_id', $user->id)->update(['captain_user_id' => null]);
            Team::where('first_pick_user_id', $user->id)->update(['first_pick_user_id' => null]);

            $user->delete();
        });

        return back();
    }

    private function storePhoto(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store('players', 'public') ?: null;
    }
}
