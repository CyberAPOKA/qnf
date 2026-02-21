<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\DraftService;
use App\Support\GamePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DraftController extends Controller
{
    public function __construct(private readonly DraftService $draftService) {}

    public function show(Request $request, Game $game): Response
    {
        $payload = GamePayload::fromGame($game, $this->draftService);

        return Inertia::render('Draft', [
            'game' => $payload,
            'current_user_id' => $request->user()->id,
            'is_admin' => $request->user()->role === 'admin',
        ]);
    }

    public function pick(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('pick', $game);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->draftService->makePick($game, (int) $validated['user_id'], $request->user()->id);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back();
    }
}
