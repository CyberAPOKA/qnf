<?php

namespace App\Jobs;

use App\Models\RecOutboxEvent;
use App\Models\RecSaveRequest;
use App\Enums\RecSaveRequestStatus;
use App\Enums\RecSaveTargetStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileRecSaveRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public ?int $saveRequestId = null) {}

    public function handle(): void
    {
        $query = RecSaveRequest::query()
            ->with('targets.clip')
            ->whereIn('status', [
                RecSaveRequestStatus::Requested->value,
                RecSaveRequestStatus::Collecting->value,
                RecSaveRequestStatus::Processing->value,
                RecSaveRequestStatus::Partial->value,
            ]);

        if ($this->saveRequestId) {
            $query->where('id', $this->saveRequestId);
        }

        foreach ($query->limit(100)->get() as $saveRequest) {
            $ready = $saveRequest->targets
                ->where('status', RecSaveTargetStatus::Ready)
                ->count();
            $failed = $saveRequest->targets
                ->whereIn('status', [RecSaveTargetStatus::Failed, RecSaveTargetStatus::CameraOffline])
                ->count();

            $saveRequest->update([
                'ready_count' => $ready,
                'failed_count' => $failed,
                'status' => match (true) {
                    $ready >= $saveRequest->expected_count && $saveRequest->expected_count > 0 => RecSaveRequestStatus::Ready,
                    $ready > 0 => RecSaveRequestStatus::Partial,
                    $failed >= $saveRequest->expected_count && $saveRequest->expected_count > 0 => RecSaveRequestStatus::Failed,
                    default => $saveRequest->status,
                },
                'completed_at' => $ready >= $saveRequest->expected_count && $saveRequest->expected_count > 0
                    ? ($saveRequest->completed_at ?? now())
                    : $saveRequest->completed_at,
            ]);

            foreach ($saveRequest->targets as $target) {
                if (
                    in_array($target->status, [RecSaveTargetStatus::RawReady, RecSaveTargetStatus::Collecting], true)
                    && $target->segments_received > 0
                    && (! $target->expected_until || $target->expected_until->isPast())
                    && ! $target->clip
                ) {
                    FinalizeRecSaveTarget::dispatch($target->id)
                        ->onQueue(config('rec.processing_queue'));
                }
            }
        }
    }
}
