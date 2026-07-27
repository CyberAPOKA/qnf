<?php

namespace App\Instagram\Jobs;

use App\Instagram\Enums\InstagramMediaType;
use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Exceptions\InstagramApiException;
use App\Instagram\Exceptions\InstagramAssetException;
use App\Instagram\Services\InstagramContainerService;
use App\Instagram\Services\InstagramMediaValidator;
use App\Instagram\Services\InstagramPublicationPreparer;
use App\Instagram\Services\InstagramAssetService;
use App\Models\InstagramPublication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessInstagramPublicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900, 1800];

    public int $timeout = 300;

    public function __construct(
        public int $publicationId,
    ) {
        $this->afterCommit = true;
        $this->onQueue((string) config('instagram.queue', 'default'));
    }

    public function handle(
        InstagramPublicationPreparer $preparer,
        InstagramMediaValidator $validator,
        InstagramContainerService $containerService,
        InstagramAssetService $assetService,
    ): void {
        $lock = Cache::lock('instagram-publication-'.$this->publicationId, 320);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $publication = InstagramPublication::query()
                ->with('items')
                ->find($this->publicationId);

            if (! $publication) {
                return;
            }

            if ($publication->status->isTerminal() && $publication->status !== InstagramPublicationStatus::Failed) {
                return;
            }

            $publication->update([
                'attempts' => $publication->attempts + 1,
                'processing_started_at' => $publication->processing_started_at ?? now(),
            ]);

            try {
                $this->advance($publication->fresh('items'), $preparer, $validator, $containerService, $assetService);
            } catch (InstagramApiException $e) {
                if ($e->permanent) {
                    $publication->markFailed(
                        InstagramApiException::sanitizeMessage($e->getMessage()),
                        (string) ($e->errorCode ?? 'instagram_api')
                    );

                    return;
                }

                throw $e;
            } catch (InstagramAssetException $e) {
                $publication->markFailed($e->getMessage(), 'asset');

                return;
            }
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $publication = InstagramPublication::query()->find($this->publicationId);

        if (! $publication) {
            return;
        }

        $message = $exception
            ? InstagramApiException::sanitizeMessage($exception->getMessage())
            : 'Instagram publication job failed.';

        $code = $exception instanceof InstagramApiException
            ? (string) ($exception->errorCode ?? 'instagram_api')
            : ($exception ? class_basename($exception) : 'job_failed');

        $publication->markFailed($message, $code);

        Log::error('Instagram publication job failed permanently', [
            'publication_uuid' => $publication->uuid,
            'publication_id' => $publication->id,
            'error' => $message,
            'error_code' => $code,
        ]);
    }

    private function advance(
        InstagramPublication $publication,
        InstagramPublicationPreparer $preparer,
        InstagramMediaValidator $validator,
        InstagramContainerService $containerService,
        InstagramAssetService $assetService,
    ): void {
        if (in_array($publication->status, [
            InstagramPublicationStatus::Pending,
            InstagramPublicationStatus::Preparing,
            InstagramPublicationStatus::Failed,
            InstagramPublicationStatus::Deferred,
        ], true) || empty($publication->payload)) {
            $publication->update(['status' => InstagramPublicationStatus::Preparing]);
            $preparer->preparePayload($publication->fresh());
            $publication = $publication->fresh('items');
        }

        if (in_array($publication->status, [
            InstagramPublicationStatus::Preparing,
            InstagramPublicationStatus::Rendering,
            InstagramPublicationStatus::Pending,
        ], true) || $publication->items->isEmpty()) {
            $publication->update(['status' => InstagramPublicationStatus::Rendering]);
            $preparer->renderAssets($publication->fresh());
            $publication = $publication->fresh('items');
        }

        if (in_array($publication->status, [
            InstagramPublicationStatus::Rendering,
            InstagramPublicationStatus::Validating,
        ], true)) {
            $publication->update(['status' => InstagramPublicationStatus::Validating]);
            $this->validateAssets($publication->fresh('items'), $validator, $assetService);
            $publication = $publication->fresh('items');
            $publication->update(['status' => InstagramPublicationStatus::CreatingContainers]);
        }

        if (config('instagram.dry_run')) {
            $containerService->syncContainers($publication->fresh('items'));

            return;
        }

        if (in_array($publication->status, [
            InstagramPublicationStatus::CreatingContainers,
            InstagramPublicationStatus::WaitingContainers,
            InstagramPublicationStatus::Validating,
            InstagramPublicationStatus::Publishing,
        ], true) && ! $publication->instagram_media_id) {
            $publication->update(['status' => InstagramPublicationStatus::CreatingContainers]);

            try {
                $ready = $containerService->syncContainers($publication->fresh('items'));
            } catch (InstagramApiException $e) {
                if ($e->transient) {
                    $publication->update([
                        'status' => InstagramPublicationStatus::WaitingContainers,
                        'last_error_message' => InstagramApiException::sanitizeMessage($e->getMessage()),
                        'last_error_code' => (string) ($e->errorCode ?? 'transient'),
                    ]);
                    $this->release($this->nextDelay());

                    return;
                }

                throw $e;
            }

            $publication = $publication->fresh('items');

            if (! $ready) {
                $publication->update(['status' => InstagramPublicationStatus::WaitingContainers]);
                $this->release($this->nextDelay());

                return;
            }

            $publication->update(['status' => InstagramPublicationStatus::Publishing]);
            $containerService->publish($publication->fresh('items'));
        }
    }

    private function validateAssets(
        InstagramPublication $publication,
        InstagramMediaValidator $validator,
        InstagramAssetService $assetService,
    ): void {
        if ($publication->items->isEmpty()) {
            throw new InstagramAssetException('No media items available for validation.');
        }

        $purpose = match ($publication->publication_type) {
            InstagramPublicationType::WeeklyTeamsCarousel => 'feed',
            default => 'story',
        };

        foreach ($publication->items as $item) {
            $absolute = $assetService->absolutePath((string) $item->local_path);
            $mediaType = strtoupper((string) $item->media_type);

            if ($mediaType === InstagramMediaType::Video->value) {
                $probe = $validator->validateVideoWithFfprobe($absolute);
                $metadata = $item->metadata ?? [];
                $metadata['ffprobe'] = $probe['validation'] ?? null;
                $item->update(['metadata' => $metadata]);
            } else {
                $validator->validateImage($absolute, $purpose);
            }

            if (! $item->public_url) {
                $item->update([
                    'public_url' => $assetService->publicUrl((string) $item->local_path),
                ]);
            }
        }
    }

    private function nextDelay(): int
    {
        $attempt = max(1, $this->attempts());
        $index = min($attempt - 1, count($this->backoff) - 1);

        return $this->backoff[$index];
    }
}
