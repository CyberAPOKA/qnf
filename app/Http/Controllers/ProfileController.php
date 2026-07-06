<?php

namespace App\Http\Controllers;

use App\Enums\Position;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    public function updateMusic(Request $request): RedirectResponse
    {
        $source = $request->input('music_source', 'youtube');

        $rules = [
            'music_source' => ['required', Rule::in(['youtube', 'mp3'])],
            'music_title' => ['required', 'string', 'max:255'],
            'music_start_seconds' => ['required', 'integer', 'min:0'],
            'music_end_seconds' => ['required', 'integer', 'min:1'],
            'music_duration_seconds' => ['required', 'integer', 'min:20', 'max:60'],
        ];

        if ($source === 'youtube') {
            $rules['music_youtube_id'] = ['required', 'string', 'max:50'];
            $rules['music_channel'] = ['nullable', 'string', 'max:255'];
            $rules['music_thumbnail_url'] = ['nullable', 'string', 'max:1000'];
            $rules['music_watch_url'] = ['nullable', 'string', 'max:1000'];
        } else {
            $rules['music_file'] = ['nullable', 'file', 'mimes:mp3,mpeg', 'max:15360'];
        }

        $validated = $request->validate($rules);

        $validator = validator($validated);

        $validator->after(function (Validator $validator) use ($validated, $source, $request) {
            $start = $validated['music_start_seconds'];
            $end = $validated['music_end_seconds'];
            $clip = $end - $start;

            if ($end <= $start) {
                $validator->errors()->add('music_end_seconds', 'O fim do trecho deve ser maior que o início.');
            }

            if ($clip < 20 || $clip > 60) {
                $validator->errors()->add('music_duration_seconds', 'O trecho deve ter entre 20 e 60 segundos.');
            }

            if ($source === 'mp3' && ! $request->hasFile('music_file') && ! $request->user()->music_file_path) {
                $validator->errors()->add('music_file', 'Envie um arquivo MP3.');
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $validated['music_duration_seconds'] = $validated['music_end_seconds'] - $validated['music_start_seconds'];

        $user = $request->user();

        $data = [
            'music_source' => $source,
            'music_title' => $validated['music_title'],
            'music_start_seconds' => $validated['music_start_seconds'],
            'music_end_seconds' => $validated['music_end_seconds'],
            'music_duration_seconds' => $validated['music_duration_seconds'],
        ];

        if ($source === 'youtube') {
            $data['music_youtube_id'] = $validated['music_youtube_id'];
            $data['music_channel'] = $validated['music_channel'] ?? null;
            $data['music_thumbnail_url'] = $validated['music_thumbnail_url'] ?? null;
            $data['music_watch_url'] = $validated['music_watch_url'] ?? null;

            if ($user->music_file_path) {
                Storage::disk('public')->delete($user->music_file_path);
                $data['music_file_path'] = null;
            }
        } else {
            $data['music_youtube_id'] = null;
            $data['music_channel'] = null;
            $data['music_thumbnail_url'] = null;
            $data['music_watch_url'] = null;

            if ($request->hasFile('music_file')) {
                if ($user->music_file_path) {
                    Storage::disk('public')->delete($user->music_file_path);
                }

                $data['music_file_path'] = $request->file('music_file')->store('music', 'public');
            }
        }

        $user->update($data);

        return back();
    }
}
