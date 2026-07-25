<?php

namespace App\Console\Commands;

use App\Models\RecSaveRequest;
use Illuminate\Console\Command;

class RecInspectSaveCommand extends Command
{
    protected $signature = 'rec:inspect-save {uuid}';

    protected $description = 'Inspect a REC save request by UUID';

    public function handle(): int
    {
        $save = RecSaveRequest::query()
            ->with(['targets.clip', 'triggeredBy', 'clips'])
            ->where('uuid', $this->argument('uuid'))
            ->first();

        if (! $save) {
            $this->error('Save request not found.');

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['uuid', $save->uuid],
                ['game_id', $save->game_id],
                ['status', $save->status?->value],
                ['capture_scope', $save->capture_scope],
                ['triggered_by', $save->triggeredBy?->name],
                ['triggered_at', $save->triggered_at],
                ['capture_from', $save->capture_from],
                ['capture_until', $save->capture_until],
                ['expected_count', $save->expected_count],
                ['ready_count', $save->ready_count],
                ['failed_count', $save->failed_count],
            ],
        );

        $this->table(
            ['target_id', 'camera', 'status', 'segments_received', 'clip_status'],
            $save->targets->map(fn ($target) => [
                $target->id,
                $target->camera_tag,
                $target->status->value,
                $target->segments_received,
                $target->clip?->status?->value,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
