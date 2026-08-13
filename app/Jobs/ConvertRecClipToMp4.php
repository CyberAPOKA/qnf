<?php

namespace App\Jobs;

use App\Models\RecClip;
use App\Services\RecClipNormalizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ConvertRecClipToMp4 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $clipId) {}

    public function handle(RecClipNormalizeService $normalize): void
    {
        $clip = RecClip::query()->find($this->clipId);

        if (! $clip) {
            return;
        }

        $normalize->ensureMp4($clip);
    }
}
