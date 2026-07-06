<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Http\Requests\ConvertGuestRequest;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AdminPlayerController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->role === 'admin', 403);

        $players = User::where('role', '!=', 'admin')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
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
            ]);

        $doneGames = Game::where('status', GameStatus::DONE)
            ->orderByDesc('round')
            ->get(['id', 'round']);

        return Inertia::render('AdminPlayers', [
            'players' => $players,
            'done_games' => $doneGames,
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

    private function storePhoto(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store('players', 'public') ?: null;
    }
}
