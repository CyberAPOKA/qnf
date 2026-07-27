<?php

namespace App\Instagram\Services;

use App\Instagram\Data\InstagramTagData;
use App\Instagram\Enums\InstagramMediaType;
use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Exceptions\InstagramApiException;
use App\Instagram\Exceptions\InstagramAssetException;
use App\Instagram\Exceptions\InstagramConfigurationException;
use App\Models\InstagramPublication;
use App\Models\InstagramPublicationItem;
use Illuminate\Support\Facades\Log;

class InstagramContainerService
{
    public function __construct(
        private readonly InstagramApiClient $apiClient,
        private readonly InstagramTokenService $tokenService,
        private readonly InstagramTagService $tagService,
        private readonly InstagramAssetService $assetService,
    ) {}

    /**
     * Create missing containers and reconcile existing ones.
     * Returns true when every container is FINISHED and ready to publish.
     */
    public function syncContainers(InstagramPublication $publication): bool
    {
        if (config('instagram.dry_run')) {
            $publication->update([
                'status' => InstagramPublicationStatus::DryRunCompleted,
                'published_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            $publication->items()->update([
                'status' => InstagramPublicationStatus::DryRunCompleted,
            ]);

            return true;
        }

        $publication->loadMissing('items');
        $token = $this->tokenService->accessToken();
        $igUserId = $this->tokenService->resolveAccount()->instagram_user_id;

        if ($publication->publication_type === InstagramPublicationType::WeeklyTeamsCarousel) {
            return $this->syncCarousel($publication, $igUserId, $token);
        }

        $item = $publication->items->sortBy('position')->first();

        if (! $item) {
            throw new InstagramAssetException('Publication has no media items to publish.');
        }

        $this->ensureItemContainer($publication, $item, $igUserId, $token, isCarouselItem: false);

        $isVideo = $this->itemIsVideo($item);
        $status = $this->reconcileContainer($item->instagram_container_id, $token);
        $ready = $this->containerIsReady($status, $isVideo);

        $item->update([
            'instagram_container_id' => $status->id,
            'status' => $ready
                ? InstagramPublicationStatus::Publishing
                : $this->statusFromContainer($status),
            'last_error' => $status->isError() ? ($status->status ?? $status->statusCode) : null,
        ]);

        if ($status->isError()) {
            throw new InstagramApiException(
                message: 'Instagram container failed: '.($status->status ?? $status->statusCode ?? 'ERROR'),
                permanent: true,
            );
        }

        if (! $ready) {
            $publication->update([
                'instagram_container_id' => $status->id,
                'status' => InstagramPublicationStatus::WaitingContainers,
            ]);

            return false;
        }

        $publication->update([
            'instagram_container_id' => $status->id,
            'status' => InstagramPublicationStatus::Publishing,
        ]);

        return true;
    }

    public function publish(InstagramPublication $publication): void
    {
        if (config('instagram.dry_run')) {
            $publication->update([
                'status' => InstagramPublicationStatus::DryRunCompleted,
                'published_at' => now(),
            ]);

            return;
        }

        if ($publication->instagram_media_id) {
            $publication->update([
                'status' => InstagramPublicationStatus::Published,
                'published_at' => $publication->published_at ?? now(),
            ]);

            return;
        }

        $publication->loadMissing('items');

        $containerId = $publication->instagram_container_id;

        if (! $containerId) {
            throw new InstagramConfigurationException('Cannot publish without an Instagram container id.');
        }

        $token = $this->tokenService->accessToken();
        $igUserId = $this->tokenService->resolveAccount()->instagram_user_id;

        $status = $this->apiClient->getContainerStatus($containerId, $token);
        $isVideo = $publication->items->contains(fn ($item) => $this->itemIsVideo($item));

        if ($status->isError()) {
            throw new InstagramApiException(
                message: 'Instagram container not publishable: '.($status->status ?? $status->statusCode ?? 'ERROR'),
                permanent: true,
            );
        }

        if (! $this->containerIsReady($status, $isVideo)) {
            throw new InstagramApiException(
                message: 'Instagram container still processing.',
                transient: true,
            );
        }

        $published = $this->apiClient->publishMedia($igUserId, $token, $containerId);
        $mediaId = $published['id'];
        $permalink = null;

        try {
            $permalink = $this->apiClient->getMediaPermalink($mediaId, $token);
        } catch (InstagramApiException $e) {
            Log::warning('Instagram permalink fetch failed after publish', [
                'publication_uuid' => $publication->uuid,
                'instagram_media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
        }

        $publication->update([
            'instagram_media_id' => $mediaId,
            'permalink' => $permalink,
            'status' => InstagramPublicationStatus::Published,
            'published_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'failed_at' => null,
        ]);

        $publication->items()->update([
            'status' => InstagramPublicationStatus::Published,
        ]);
    }

    private function syncCarousel(InstagramPublication $publication, string $igUserId, string $token): bool
    {
        $children = $publication->items->sortBy('position')->values();

        if ($children->isEmpty()) {
            throw new InstagramAssetException('Carousel publication has no items.');
        }

        $childIds = [];

        foreach ($children as $item) {
            $this->ensureItemContainer($publication, $item, $igUserId, $token, isCarouselItem: true);
            $status = $this->reconcileContainer($item->instagram_container_id, $token);
            $ready = $this->containerIsReady($status, $this->itemIsVideo($item));

            $item->update([
                'instagram_container_id' => $status->id,
                'status' => $ready
                    ? InstagramPublicationStatus::Publishing
                    : $this->statusFromContainer($status),
                'last_error' => $status->isError() ? ($status->status ?? $status->statusCode) : null,
            ]);

            if ($status->isError()) {
                throw new InstagramApiException(
                    message: 'Carousel child container failed: '.($status->status ?? $status->statusCode ?? 'ERROR'),
                    permanent: true,
                );
            }

            if (! $ready) {
                $publication->update(['status' => InstagramPublicationStatus::WaitingContainers]);

                return false;
            }

            $childIds[] = $status->id;
        }

        if (! $publication->instagram_container_id) {
            $parent = $this->apiClient->createMediaContainer($igUserId, $token, [
                'media_type' => InstagramMediaType::Carousel->value,
                'children' => implode(',', $childIds),
                'caption' => (string) ($publication->payload['caption'] ?? ''),
            ]);

            $publication->update([
                'instagram_container_id' => $parent->id,
                'status' => InstagramPublicationStatus::WaitingContainers,
            ]);
        }

        $parentStatus = $this->reconcileContainer($publication->instagram_container_id, $token);
        $parentReady = $this->containerIsReady($parentStatus, isVideo: false);

        $publication->update([
            'instagram_container_id' => $parentStatus->id,
            'status' => $parentReady
                ? InstagramPublicationStatus::Publishing
                : InstagramPublicationStatus::WaitingContainers,
        ]);

        if ($parentStatus->isError()) {
            throw new InstagramApiException(
                message: 'Carousel parent container failed: '.($parentStatus->status ?? $parentStatus->statusCode ?? 'ERROR'),
                permanent: true,
            );
        }

        return $parentReady;
    }

    private function ensureItemContainer(
        InstagramPublication $publication,
        InstagramPublicationItem $item,
        string $igUserId,
        string $token,
        bool $isCarouselItem,
    ): void {
        if ($item->instagram_container_id) {
            return;
        }

        $publicUrl = $item->public_url ?: $this->assetService->publicUrl((string) $item->local_path);

        if (! $publicUrl || ! str_starts_with($publicUrl, 'http')) {
            throw new InstagramAssetException('Public media URL is required to create an Instagram container.');
        }

        if (! $item->public_url) {
            $item->update(['public_url' => $publicUrl]);
        }

        $params = $this->buildItemParams($publication, $item, $publicUrl, $isCarouselItem);
        $container = $this->createContainerWithTagFallback($igUserId, $token, $params, $publication, $item);

        $item->update([
            'instagram_container_id' => $container->id,
            'status' => InstagramPublicationStatus::CreatingContainers,
        ]);

        if (! $isCarouselItem) {
            $publication->update([
                'instagram_container_id' => $container->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createContainerWithTagFallback(
        string $igUserId,
        string $token,
        array $params,
        InstagramPublication $publication,
        InstagramPublicationItem $item,
    ): \App\Instagram\Data\InstagramContainerData {
        try {
            return $this->apiClient->createMediaContainer($igUserId, $token, $params);
        } catch (InstagramApiException $e) {
            if (! $this->isTagRelatedError($e) || empty($params['user_tags'])) {
                throw $e;
            }

            Log::warning('Instagram user_tags rejected; retrying without tags', [
                'publication_uuid' => $publication->uuid,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ]);

            $metadata = $item->metadata ?? [];
            $metadata['tags_stripped'] = true;
            $metadata['tags_error'] = InstagramApiException::sanitizeMessage($e->getMessage());
            $item->update(['metadata' => $metadata]);

            unset($params['user_tags']);

            return $this->apiClient->createMediaContainer($igUserId, $token, $params);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItemParams(
        InstagramPublication $publication,
        InstagramPublicationItem $item,
        string $publicUrl,
        bool $isCarouselItem,
    ): array {
        $mediaType = strtoupper((string) $item->media_type);
        $params = [];

        if ($isCarouselItem) {
            $params['is_carousel_item'] = true;
        }

        if ($mediaType === InstagramMediaType::Video->value || $mediaType === 'VIDEO') {
            $params['media_type'] = $publication->publication_type === InstagramPublicationType::WeeklyTeamStory
                || $publication->publication_type === InstagramPublicationType::DraftStory
                ? InstagramMediaType::Stories->value
                : InstagramMediaType::Video->value;
            $params['video_url'] = $publicUrl;
        } elseif (
            $publication->publication_type === InstagramPublicationType::DraftStory
            || $publication->publication_type === InstagramPublicationType::WeeklyTeamStory
        ) {
            $params['media_type'] = InstagramMediaType::Stories->value;
            $params['image_url'] = $publicUrl;
        } else {
            $params['image_url'] = $publicUrl;
        }

        if (! $isCarouselItem && ! empty($publication->payload['caption'])) {
            $params['caption'] = (string) $publication->payload['caption'];
        }

        $usernames = $item->metadata['taggable_usernames']
            ?? $publication->payload['taggable_usernames']
            ?? [];

        // Stories accept username; x/y are optional but improve placement for mentions.
        if (is_array($usernames) && $usernames !== []) {
            $tags = $this->tagService->distributeTags($usernames);
            if ($tags !== []) {
                $params['user_tags'] = $this->tagService->toApiJson($tags);
            }
        } elseif (! empty($item->metadata['user_tags']) && is_array($item->metadata['user_tags'])) {
            $tags = array_map(
                fn (array $tag) => new InstagramTagData(
                    username: (string) $tag['username'],
                    x: (float) ($tag['x'] ?? 0.5),
                    y: (float) ($tag['y'] ?? 0.5),
                ),
                $item->metadata['user_tags']
            );
            $params['user_tags'] = $this->tagService->toApiJson($tags);
        }

        if (! empty($params['user_tags'])) {
            Log::info('Instagram container including user_tags', [
                'publication_uuid' => $publication->uuid,
                'item_id' => $item->id,
                'tags_json' => $params['user_tags'],
            ]);
        }

        return $params;
    }

    private function reconcileContainer(string $containerId, string $token): \App\Instagram\Data\InstagramContainerData
    {
        return $this->apiClient->getContainerStatus($containerId, $token);
    }

    private function containerIsReady(\App\Instagram\Data\InstagramContainerData $status, bool $isVideo): bool
    {
        if ($status->isError()) {
            return false;
        }

        if ($status->isFinished()) {
            return true;
        }

        // Image containers often omit status_code and are publishable immediately.
        if (! $isVideo && ($status->statusCode === null || $status->statusCode === '')) {
            return true;
        }

        return false;
    }

    private function itemIsVideo(InstagramPublicationItem $item): bool
    {
        return strtoupper((string) $item->media_type) === InstagramMediaType::Video->value;
    }

    private function statusFromContainer(\App\Instagram\Data\InstagramContainerData $status): InstagramPublicationStatus
    {
        if ($status->isFinished()) {
            return InstagramPublicationStatus::Publishing;
        }

        if ($status->isError()) {
            return InstagramPublicationStatus::Failed;
        }

        return InstagramPublicationStatus::WaitingContainers;
    }

    private function isTagRelatedError(InstagramApiException $e): bool
    {
        $haystack = strtolower($e->getMessage().' '.($e->errorType ?? ''));

        return str_contains($haystack, 'tag')
            || str_contains($haystack, 'mention')
            || str_contains($haystack, 'user_tags')
            || str_contains($haystack, 'tagged user');
    }
}
