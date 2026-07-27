<?php

namespace App\Instagram\Services;

use App\Enums\TeamColor;
use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Enums\InstagramTriggerType;
use App\Instagram\Exceptions\InstagramConfigurationException;
use App\Instagram\Jobs\ProcessInstagramPublicationJob;
use App\Instagram\Support\InstagramIdempotencyKey;
use App\Models\Game;
use App\Models\InstagramPublication;
use App\Services\WeekTeamImageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramPublishingService
{
    public function __construct(
        private readonly WeekTeamImageService $weekTeamImageService,
        private readonly InstagramTokenService $tokenService,
    ) {}

    public function queueDraftStory(Game $game): ?InstagramPublication
    {
        if (! config('instagram.enabled')) {
            return null;
        }

        $publication = $this->createPublication(
            triggerType: InstagramTriggerType::DraftFinalized,
            triggerId: $game->id,
            triggerVersion: 'v1',
            publicationType: InstagramPublicationType::DraftStory,
            payload: [],
            metadata: ['game_id' => $game->id],
        );

        $this->dispatchIfNeeded($publication);

        return $publication;
    }

    /**
     * @return list<InstagramPublication>
     */
    public function queueMatchResultPublications(Game $game): array
    {
        if (! config('instagram.enabled')) {
            return [];
        }

        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser', 'weekTeamMusics']);

        $scores = $game->teams
            ->mapWithKeys(fn ($team) => [$team->color->value => $team->score === null ? null : (int) $team->score])
            ->all();
        ksort($scores);

        $triggerVersion = 'v'.substr(hash('sha256', json_encode($scores, JSON_THROW_ON_ERROR)), 0, 16);
        $winnerColors = $this->weekTeamImageService->getWinnerColors($game);

        $publications = [];

        $carousel = $this->createPublication(
            triggerType: InstagramTriggerType::MatchResult,
            triggerId: $game->id,
            triggerVersion: $triggerVersion,
            publicationType: InstagramPublicationType::WeeklyTeamsCarousel,
            payload: [],
            metadata: [
                'game_id' => $game->id,
                'winner_colors' => array_map(
                    fn (TeamColor $color) => $color->value,
                    $winnerColors
                ),
                'scores' => $scores,
            ],
        );
        $publications[] = $carousel;
        $this->dispatchIfNeeded($carousel);

        foreach ($winnerColors as $color) {
            $story = $this->createPublication(
                triggerType: InstagramTriggerType::MatchResult,
                triggerId: $game->id,
                triggerVersion: $triggerVersion,
                publicationType: InstagramPublicationType::WeeklyTeamStory,
                payload: [],
                metadata: [
                    'game_id' => $game->id,
                    'team_color' => $color->value,
                    'scores' => $scores,
                ],
                idempotencySuffix: 'team-'.$color->value,
            );
            $publications[] = $story;
            $this->dispatchIfNeeded($story);
        }

        return $publications;
    }

    public function retry(InstagramPublication $publication): void
    {
        if ($publication->status === InstagramPublicationStatus::Published) {
            return;
        }

        $publication->update([
            'status' => InstagramPublicationStatus::Pending,
            'failed_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'queued_at' => now(),
        ]);

        ProcessInstagramPublicationJob::dispatch($publication->id)
            ->onQueue((string) config('instagram.queue', 'default'));
    }

    private function dispatchIfNeeded(InstagramPublication $publication): void
    {
        if ($publication->status->isTerminal() && $publication->status !== InstagramPublicationStatus::Failed) {
            return;
        }

        if ($publication->wasRecentlyCreated
            || in_array($publication->status, [
                InstagramPublicationStatus::Pending,
                InstagramPublicationStatus::Failed,
                InstagramPublicationStatus::Deferred,
            ], true)
        ) {
            ProcessInstagramPublicationJob::dispatch($publication->id)
                ->onQueue((string) config('instagram.queue', 'default'));
        }
    }

    public function createPublication(
        InstagramTriggerType $triggerType,
        int|string $triggerId,
        string $triggerVersion,
        InstagramPublicationType $publicationType,
        array $payload = [],
        array $metadata = [],
        ?string $idempotencySuffix = null,
    ): InstagramPublication {
        $idempotencyKey = InstagramIdempotencyKey::make(
            $triggerType,
            $triggerId,
            $triggerVersion,
            $publicationType,
            $idempotencySuffix,
        );

        $lock = Cache::lock('instagram-publication-create:'.$idempotencyKey, 15);

        return $lock->block(10, function () use (
            $idempotencyKey,
            $triggerType,
            $triggerId,
            $triggerVersion,
            $publicationType,
            $payload,
            $metadata,
        ): InstagramPublication {
            $existing = InstagramPublication::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $account = null;
            if (! config('instagram.dry_run')) {
                try {
                    $account = $this->tokenService->resolveAccount();
                } catch (InstagramConfigurationException $e) {
                    Log::warning('Instagram account unavailable while creating publication', [
                        'idempotency_key' => $idempotencyKey,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            try {
                return InstagramPublication::create([
                    'instagram_account_id' => $account?->id,
                    'trigger_type' => $triggerType,
                    'trigger_id' => (int) $triggerId,
                    'trigger_version' => $triggerVersion,
                    'publication_type' => $publicationType,
                    'status' => InstagramPublicationStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'payload' => $payload,
                    'metadata' => $metadata,
                    'queued_at' => now(),
                ]);
            } catch (Throwable $e) {
                $existing = InstagramPublication::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                throw $e;
            }
        });
    }
}
