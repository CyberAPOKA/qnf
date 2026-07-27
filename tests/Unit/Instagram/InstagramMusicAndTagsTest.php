<?php

namespace Tests\Unit\Instagram;

use App\Instagram\Services\InstagramMusicResolver;
use App\Instagram\Services\InstagramTagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramMusicAndTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_music_resolver_prefers_captain_mp3(): void
    {
        $relative = 'music/captain-test.mp3';
        $absolute = storage_path('app/public/'.$relative);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, 'fake-mp3');

        try {
            $captain = User::factory()->create([
                'music_source' => 'mp3',
                'music_file_path' => $relative,
                'music_title' => 'Hino',
            ]);

            $resolved = app(InstagramMusicResolver::class)->resolveForCaptain($captain);

            $this->assertSame('captain', $resolved['source']);
            $this->assertSame('Hino', $resolved['title']);
            $this->assertNotNull($resolved['path']);
        } finally {
            @unlink($absolute);
        }
    }

    public function test_music_resolver_skips_youtube_only_and_falls_back_to_default(): void
    {
        $relative = 'instagram/default-test.mp3';
        $absolute = storage_path('app/public/'.$relative);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, 'default-audio');

        config([
            'instagram.default_story_audio_path' => $relative,
        ]);

        try {
            $captain = User::factory()->create([
                'music_source' => 'youtube',
                'music_youtube_id' => 'abc123',
                'music_file_path' => null,
            ]);

            $resolved = app(InstagramMusicResolver::class)->resolveForCaptain($captain);

            $this->assertSame('default', $resolved['source']);
            $this->assertNotNull($resolved['path']);
        } finally {
            @unlink($absolute);
        }
    }

    public function test_music_resolver_falls_back_to_none(): void
    {
        config(['instagram.default_story_audio_path' => null]);

        $captain = User::factory()->create([
            'music_source' => 'youtube',
            'music_youtube_id' => 'abc123',
            'music_file_path' => null,
        ]);

        $resolved = app(InstagramMusicResolver::class)->resolveForCaptain($captain);

        $this->assertSame('none', $resolved['source']);
        $this->assertNull($resolved['path']);
    }

    public function test_tag_service_distributes_distinct_positions(): void
    {
        $tags = app(InstagramTagService::class)->distributeTags([
            'alpha',
            'beta',
            'gamma',
            '@alpha',
        ]);

        $this->assertCount(3, $tags);

        $coords = array_map(fn ($tag) => $tag->x.'x'.$tag->y, $tags);
        $this->assertCount(3, array_unique($coords));
    }

    public function test_tag_service_filters_invalid_from_error_message(): void
    {
        $service = app(InstagramTagService::class);
        $tags = $service->distributeTags(['valid.user', 'bad.user']);

        $filtered = $service->filterInvalid($tags, 'Cannot tag @bad.user');

        $this->assertCount(1, $filtered);
        $this->assertSame('valid.user', $filtered[0]->username);
    }
}
