<?php

namespace App\Console\Commands;

use App\Models\RecRecorderSession;
use Illuminate\Console\Command;

class RecInspectSessionCommand extends Command
{
    protected $signature = 'rec:inspect-session {uuid}';

    protected $description = 'Inspect a REC recorder session by UUID';

    public function handle(): int
    {
        $session = RecRecorderSession::query()
            ->with(['user', 'segments'])
            ->where('uuid', $this->argument('uuid'))
            ->first();

        if (! $session) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['uuid', $session->uuid],
                ['game_id', $session->game_id],
                ['user', $session->user?->name],
                ['camera_tag', $session->camera_tag],
                ['status', $session->status->value],
                ['started_at', $session->started_at],
                ['heartbeat_at', $session->heartbeat_at],
                ['lease_expires_at', $session->lease_expires_at],
                ['last_segment_sequence', $session->last_segment_sequence],
                ['segments', $session->segments->count()],
            ],
        );

        return self::SUCCESS;
    }
}
