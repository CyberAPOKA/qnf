<?php

namespace App\Services;

use App\Exceptions\YouTube\YouTubeApiException;
use App\Exceptions\YouTube\YouTubeQuotaExceededException;
use App\Exceptions\YouTube\YouTubeVideoNotEmbeddableException;
use App\Exceptions\YouTube\YouTubeVideoNotFoundException;
use App\Exceptions\YouTube\YouTubeVideoTooShortException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class YouTubeService
{
    private const MIN_DURATION_SECONDS = 60;

    private const MAX_SEARCH_RESULTS = 10;

    private const MIN_TITLE_MATCH_RATIO = 0.75;

    /** @var array<int, string> */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for',
        'o', 'a', 'e', 'de', 'da', 'do', 'das', 'dos', 'em', 'na', 'no',
    ];

    /** @var array<int, string> */
    private const BLOCKED_TITLE_PATTERNS = [
        '/\(\s*lyrics\s*\)/i',
        '/\[\s*lyrics\s*\]/i',
        '/\blyrics?\s+video\b/i',
        '/\blyric\s+video\b/i',
        '/\bwith\s+lyrics\b/i',
        '/\btradu[cç][aã]o\b/i',
        '/\blegendado\b/i',
        '/\bsubtitul/i',
        '/\btutorial\b/i',
        '/\bcover\b/i',
        '/\bkaraoke\b/i',
        '/#shorts?\b/i',
        '/#viral\b/i',
        '/#trend\b/i',
        '/\bgacha(club|life|meme|trend)?\b/i',
        '/\b(memes?|aura\.{2,}|doppelg[aä]nger)\b/i',
    ];

    private string $apiKey;

    private string $region;

    public function __construct()
    {
        $this->apiKey = config('services.youtube.api_key', '');
        $this->region = config('services.youtube.region', 'BR');
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     title: string,
     *     channel: string,
     *     thumbnail: string,
     *     duration_seconds: int,
     *     watch_url: string,
     *     embed_url: string
     * }>
     */
    public function search(string $query): array
    {
        $this->ensureApiKey();

        $searchResponse = $this->request('https://www.googleapis.com/youtube/v3/search', [
            'part' => 'snippet',
            'type' => 'video',
            'videoEmbeddable' => 'true',
            'videoSyndicated' => 'true',
            'videoCategoryId' => '10',
            'order' => 'relevance',
            'regionCode' => $this->region,
            'relevanceLanguage' => config('services.youtube.language', 'pt'),
            'q' => $query,
            'maxResults' => 50,
        ]);

        $items = $searchResponse->json('items', []);

        if (empty($items)) {
            return [];
        }

        $videoIds = collect($items)
            ->pluck('id.videoId')
            ->filter()
            ->values()
            ->all();

        if (empty($videoIds)) {
            return [];
        }

        $videosResponse = $this->request('https://www.googleapis.com/youtube/v3/videos', [
            'part' => 'snippet,contentDetails,status,player',
            'id' => implode(',', $videoIds),
        ]);

        $videosById = collect($videosResponse->json('items', []))
            ->keyBy('id');

        return $this->rankSearchResults($items, $videosById, $query);
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     channel: string,
     *     thumbnail: string,
     *     duration_seconds: int,
     *     watch_url: string,
     *     embed_url: string
     * }
     */
    public function getVideo(string $videoId): array
    {
        $this->ensureApiKey();

        $response = $this->request('https://www.googleapis.com/youtube/v3/videos', [
            'part' => 'snippet,contentDetails,status,player',
            'id' => $videoId,
        ]);

        $item = $response->json('items.0');

        if (! $item) {
            throw new YouTubeVideoNotFoundException;
        }

        if (! $this->isEmbeddableVideo($item)) {
            throw new YouTubeVideoNotEmbeddableException;
        }

        $durationSeconds = $this->parseDuration($item['contentDetails']['duration'] ?? '');

        if ($durationSeconds < self::MIN_DURATION_SECONDS) {
            throw new YouTubeVideoTooShortException;
        }

        $video = $this->formatVideo($item);

        if ($video === null) {
            throw new YouTubeVideoNotEmbeddableException;
        }

        return $video;
    }

    private function isEmbeddableVideo(array $item): bool
    {
        if (! ($item['status']['embeddable'] ?? false)) {
            return false;
        }

        if (($item['status']['privacyStatus'] ?? '') !== 'public') {
            return false;
        }

        if (blank($item['player']['embedHtml'] ?? null)) {
            return false;
        }

        if (($item['contentDetails']['contentRating']['ytRating'] ?? null) === 'ytAgeRestricted') {
            return false;
        }

        if ($this->isBlockedInRegion($item)) {
            return false;
        }

        $title = $item['snippet']['title'] ?? '';
        $channel = $item['snippet']['channelTitle'] ?? '';

        if (str_ends_with($channel, ' - Topic')) {
            return false;
        }

        if ($this->hasBlockedTitlePattern($title)) {
            return false;
        }

        return true;
    }

    private function hasBlockedTitlePattern(string $title): bool
    {
        foreach (self::BLOCKED_TITLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedInRegion(array $item): bool
    {
        $blocked = $item['contentDetails']['regionRestriction']['blocked'] ?? [];
        $allowed = $item['contentDetails']['regionRestriction']['allowed'] ?? [];

        if ($blocked !== [] && in_array($this->region, $blocked, true)) {
            return true;
        }

        if ($allowed !== [] && ! in_array($this->region, $allowed, true)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     channel: string,
     *     thumbnail: string,
     *     duration_seconds: int,
     *     watch_url: string,
     *     embed_url: string
     * }|null
     */
    private function formatVideo(array $item): ?array
    {
        $videoId = $item['id'] ?? null;

        if (! $videoId || ! $this->isEmbeddableVideo($item)) {
            return null;
        }

        $durationSeconds = $this->parseDuration($item['contentDetails']['duration'] ?? '');

        if ($durationSeconds < self::MIN_DURATION_SECONDS) {
            return null;
        }

        $channel = $item['snippet']['channelTitle'] ?? '';

        return [
            'id' => $videoId,
            'title' => $item['snippet']['title'] ?? '',
            'channel' => $channel,
            'thumbnail' => $item['snippet']['thumbnails']['medium']['url']
                ?? $item['snippet']['thumbnails']['default']['url']
                ?? '',
            'duration_seconds' => $durationSeconds,
            'watch_url' => $this->formatWatchUrl($videoId),
            'embed_url' => $this->formatEmbedUrl($videoId),
        ];
    }

    private function formatWatchUrl(string $videoId): string
    {
        return "https://www.youtube.com/watch?v={$videoId}";
    }

    private function formatEmbedUrl(string $videoId): string
    {
        return "https://www.youtube.com/embed/{$videoId}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $searchItems
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $videosById
     * @return array<int, array{
     *     id: string,
     *     title: string,
     *     channel: string,
     *     thumbnail: string,
     *     duration_seconds: int,
     *     watch_url: string,
     *     embed_url: string
     * }>
     */
    private function rankSearchResults(array $searchItems, $videosById, string $query): array
    {
        $searchOrder = [];

        foreach ($searchItems as $index => $item) {
            $videoId = $item['id']['videoId'] ?? null;

            if ($videoId) {
                $searchOrder[$videoId] = $index;
            }
        }

        $channelIds = $videosById
            ->pluck('snippet.channelId')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $channelsById = $this->fetchChannels($channelIds);

        return collect($searchItems)
            ->map(function (array $item) use ($videosById, $channelsById, $searchOrder, $query) {
                $videoId = $item['id']['videoId'] ?? null;

                if (! $videoId || ! $videosById->has($videoId)) {
                    return null;
                }

                $videoItem = $videosById->get($videoId);
                $title = $videoItem['snippet']['title'] ?? '';

                if ($this->titleMatchRatio($query, $title) < self::MIN_TITLE_MATCH_RATIO) {
                    return null;
                }

                $formatted = $this->formatVideo($videoItem);

                if ($formatted === null) {
                    return null;
                }

                $channelId = $videoItem['snippet']['channelId'] ?? '';
                $channel = $channelsById[$channelId] ?? [];

                return [
                    'video' => $formatted,
                    'score' => $this->scoreVideoCandidate(
                        $videoItem,
                        $channel,
                        $searchOrder[$videoId] ?? 999,
                        $query,
                    ),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->pluck('video')
            ->take(self::MAX_SEARCH_RESULTS)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $channelIds
     * @return array<string, array<string, mixed>>
     */
    private function fetchChannels(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $response = $this->request('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'snippet,statistics',
            'id' => implode(',', array_slice($channelIds, 0, 50)),
        ]);

        return collect($response->json('items', []))
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $video
     * @param  array<string, mixed>  $channel
     */
    private function scoreVideoCandidate(array $video, array $channel, int $searchIndex, string $query): int
    {
        $title = $video['snippet']['title'] ?? '';
        $titleLower = strtolower($title);
        $channelTitle = strtolower($video['snippet']['channelTitle'] ?? '');

        $matchRatio = $this->titleMatchRatio($query, $title);
        $score = (int) round($matchRatio * 4000);

        $queryNorm = $this->normalizeForMatch($query);
        $titleNorm = $this->normalizeForMatch($title);

        if ($queryNorm !== '' && str_contains($titleNorm, $queryNorm)) {
            $score += 1500;
        }

        $score += 100 - min($searchIndex, 99);

        if ($video['contentDetails']['licensedContent'] ?? false) {
            $score += 180;
        }

        if (str_contains($channelTitle, 'vevo')) {
            $score += 160;
        }

        if (preg_match('/\bofficial\b/', $titleLower)) {
            $score += 140;
        }

        if (preg_match('/\b(official video|official music video|music video|videoclip)\b/', $titleLower)) {
            $score += 120;
        }

        $subscribers = (int) ($channel['statistics']['subscriberCount'] ?? 0);

        if ($subscribers >= 100_000) {
            $score += min(120, (int) (log10(max(1, $subscribers)) * 15));
        } elseif ($subscribers < 500) {
            $score -= 120;
        }

        if (preg_match('/\b(lyrics|covers?|karaoke|tribute|fan|unofficial|bootleg|reupload)\b/', $channelTitle)) {
            $score -= 400;
        }

        if (preg_match('/#\w+/', $titleLower)) {
            $score -= 900;
            $score -= substr_count($titleLower, '#') * 120;
        }

        if (preg_match('/\b(audio only|8d|slowed|reverb|sped up|nightcore|without autotune|switched instruments)\b/', $titleLower)) {
            $score -= 500;
        }

        if (preg_match('/\b(ironico|ironic|unhear|bangs|horror|switched)\b/', $titleLower)) {
            $score -= 400;
        }

        return $score;
    }

    private function titleMatchRatio(string $query, string $title): float
    {
        $queryWords = $this->significantWords($query);

        if ($queryWords === []) {
            return 0;
        }

        $titleNorm = $this->normalizeForMatch($title);
        $matched = 0;

        foreach ($queryWords as $word) {
            if (str_contains($titleNorm, $word)) {
                $matched++;
            }
        }

        return $matched / count($queryWords);
    }

    /**
     * @return array<int, string>
     */
    private function significantWords(string $text): array
    {
        $words = preg_split('/\s+/', $this->normalizeForMatch($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $words,
            fn (string $word) => mb_strlen($word) >= 2 && ! in_array($word, self::STOP_WORDS, true),
        ));
    }

    private function normalizeForMatch(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace("/[''´`]/u", '', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(string $url, array $query): Response
    {
        $response = Http::get($url, [
            ...$query,
            'key' => $this->apiKey,
        ]);

        if ($response->status() === 403) {
            throw new YouTubeQuotaExceededException;
        }

        if (! $response->successful()) {
            throw new YouTubeApiException('Falha ao comunicar com a API do YouTube.');
        }

        return $response;
    }

    private function parseDuration(string $duration): int
    {
        if ($duration === '') {
            return 0;
        }

        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $matches);

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function ensureApiKey(): void
    {
        if (blank($this->apiKey)) {
            throw new YouTubeApiException('Chave da API do YouTube não configurada.');
        }
    }
}
