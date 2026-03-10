<?php

namespace App\Http\Controllers;

use App\Enums\Position;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {}

    public function updatePosition(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'position' => ['required', Rule::in([Position::FIXED->value, Position::WINGER->value, Position::PIVOT->value])],
        ]);

        $this->profileService->updatePosition($request->user(), $validated['position']);

        return back();
    }

    public function updateWhatsAppNotifications(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'whatsapp_notifications' => ['required', 'boolean'],
        ]);

        $request->user()->update($validated);

        return back();
    }
}
