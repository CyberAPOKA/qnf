<?php

namespace App\Http\Controllers;

use App\Enums\Position;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
                'active' => $user->active,
                'photo_front' => $user->photo_front ? Storage::url($user->photo_front) : null,
                'photo_side' => $user->photo_side ? Storage::url($user->photo_side) : null,
            ]);

        return Inertia::render('AdminPlayers', [
            'players' => $players,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'position' => ['required', Rule::in(Position::values())],
            'password' => ['required', 'string', 'min:4'],
            'active' => ['boolean'],
            'photo_front' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'photo_side' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

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

        if ($request->hasFile('photo_front')) {
            $data['photo_front'] = $request->file('photo_front')->store('players', 'public');
        }

        if ($request->hasFile('photo_side')) {
            $data['photo_side'] = $request->file('photo_side')->store('players', 'public');
        }

        User::create($data);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'position' => ['required', Rule::in(Position::values())],
            'active' => ['boolean'],
            'photo_front' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'photo_side' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['phone'].'@player.local',
            'position' => $validated['position'],
            'active' => $validated['active'] ?? true,
        ]);

        if ($request->hasFile('photo_front')) {
            if ($user->photo_front) {
                Storage::disk('public')->delete($user->photo_front);
            }
            $user->photo_front = $request->file('photo_front')->store('players', 'public');
        }

        if ($request->hasFile('photo_side')) {
            if ($user->photo_side) {
                Storage::disk('public')->delete($user->photo_side);
            }
            $user->photo_side = $request->file('photo_side')->store('players', 'public');
        }

        $user->save();

        return back();
    }
}
