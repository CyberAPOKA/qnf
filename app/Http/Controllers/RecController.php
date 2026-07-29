<?php

namespace App\Http\Controllers;

use App\Services\Rec\RecRecorderSessionService;
use App\Services\RecSessionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecController extends Controller
{
    public function __construct(
        private readonly RecSessionService $recSession,
        private readonly RecRecorderSessionService $recorderSessions,
    ) {}

    public function show(Request $request, \App\Models\Game $game): Response
    {
        return Inertia::render('Rec', [
            'game' => [
                'id' => $game->id,
                'date' => $game->date?->format('d/m/Y'),
                'round' => $game->round,
                'status' => $game->status->value,
            ],
            'recorders' => $this->recorderSessions->listActiveForGame($game->id),
            'recent_saves' => $this->recSession->recentSaveRequests($game),
            'buffer_seconds' => $this->recSession->bufferSeconds(),
            'current_user_id' => $request->user()->id,
            'current_user_name' => $request->user()->name,
            'rec_config' => [
                'segment_seconds' => (int) config('rec.segment_seconds', 5),
                'buffer_seconds' => (int) config('rec.buffer_seconds', 30),
                'local_retention_seconds' => (int) config('rec.local_retention_seconds', 180),
                'post_roll_seconds' => (int) config('rec.post_roll_seconds', 2),
                'heartbeat_seconds' => (int) config('rec.heartbeat_seconds', 10),
                'recorder_lease_seconds' => (int) config('rec.recorder_lease_seconds', 35),
                'save_debounce_milliseconds' => (int) config('rec.save_debounce_milliseconds', 800),
                'save_scope_cooldown_seconds' => (int) config('rec.save_scope_cooldown_seconds', 10),
                'pending_save_poll_seconds' => (int) config('rec.pending_save_poll_seconds', 2),
                'upload_max_concurrency' => (int) config('rec.upload_max_concurrency', 1),
                'upload_request_timeout_seconds' => (int) config('rec.upload_request_timeout_seconds', 120),
            ],
        ]);
    }
}
