<?php

namespace App\Instagram\Data;

readonly class InstagramMediaData
{
    /**
     * @param  list<InstagramTagData>  $userTags
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $mediaType,
        public string $localPath,
        public ?string $publicUrl = null,
        public ?string $caption = null,
        public array $userTags = [],
        public array $metadata = [],
        public bool $isCarouselItem = false,
    ) {}
}
