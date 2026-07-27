<?php

namespace App\Instagram\Jobs;

use App\Instagram\Services\InstagramTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshInstagramTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $afterCommit = true;

    public function __construct()
    {
        $this->onQueue((string) config('instagram.queue', 'default'));
    }

    public function handle(InstagramTokenService $tokenService): void
    {
        try {
            $account = $tokenService->refreshIfNeeded();

            Log::info('Instagram token refresh job completed', [
                'account_id' => $account?->id,
                'status' => $account?->status?->value,
                'expires_at' => $account?->access_token_expires_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('Instagram token refresh job failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
