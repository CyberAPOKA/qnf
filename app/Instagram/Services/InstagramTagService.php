<?php

namespace App\Instagram\Services;

use App\Instagram\Data\InstagramTagData;
use App\Instagram\Support\InstagramUsernameNormalizer;

class InstagramTagService
{
    /**
     * @param  list<string>  $usernames
     * @return list<InstagramTagData>
     */
    public function distributeTags(array $usernames): array
    {
        $normalized = [];

        foreach ($usernames as $username) {
            $value = InstagramUsernameNormalizer::tryNormalize((string) $username);

            if ($value === null || isset($normalized[$value])) {
                continue;
            }

            $normalized[$value] = true;
        }

        $names = array_keys($normalized);
        $count = count($names);

        if ($count === 0) {
            return [];
        }

        $positions = $this->safePositions($count);
        $tags = [];

        foreach ($names as $index => $username) {
            [$x, $y] = $positions[$index];
            $tags[] = new InstagramTagData(username: $username, x: $x, y: $y);
        }

        return $tags;
    }

    /**
     * @param  list<InstagramTagData>  $tags
     */
    public function toApiJson(array $tags): string
    {
        $payload = array_map(
            fn (InstagramTagData $tag) => $tag->toApiArray(),
            array_values($tags)
        );

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @param  list<InstagramTagData>  $tags
     * @return list<InstagramTagData>
     */
    public function filterInvalid(array $tags, string $errorMessage): array
    {
        $invalid = $this->extractUsernamesFromError($errorMessage);

        if ($invalid === []) {
            return array_values($tags);
        }

        $invalidLookup = array_fill_keys($invalid, true);

        return array_values(array_filter(
            $tags,
            fn (InstagramTagData $tag) => ! isset($invalidLookup[strtolower($tag->username)])
        ));
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function safePositions(int $count): array
    {
        $columns = (int) max(1, ceil(sqrt($count)));
        $rows = (int) max(1, ceil($count / $columns));

        $minX = 0.18;
        $maxX = 0.82;
        $minY = 0.22;
        $maxY = 0.78;

        $positions = [];
        $index = 0;

        for ($row = 0; $row < $rows && $index < $count; $row++) {
            for ($col = 0; $col < $columns && $index < $count; $col++) {
                $x = $columns === 1
                    ? 0.5
                    : $minX + (($maxX - $minX) * ($col / ($columns - 1)));
                $y = $rows === 1
                    ? 0.5
                    : $minY + (($maxY - $minY) * ($row / ($rows - 1)));

                $jitterX = (($index % 3) - 1) * 0.02;
                $jitterY = ((($index + 1) % 3) - 1) * 0.015;

                $positions[] = [
                    round(max(0.05, min(0.95, $x + $jitterX)), 4),
                    round(max(0.05, min(0.95, $y + $jitterY)), 4),
                ];
                $index++;
            }
        }

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function extractUsernamesFromError(string $errorMessage): array
    {
        $found = [];

        if (preg_match_all('/@([A-Za-z0-9._]{1,30})/', $errorMessage, $atMatches)) {
            foreach ($atMatches[1] as $username) {
                $normalized = InstagramUsernameNormalizer::tryNormalize($username);

                if ($normalized !== null) {
                    $found[$normalized] = true;
                }
            }
        }

        if (preg_match_all('/\b(?:user(?:name)?|tag)\s*[:=]\s*["\']?([A-Za-z0-9._]{1,30})/i', $errorMessage, $namedMatches)) {
            foreach ($namedMatches[1] as $username) {
                $normalized = InstagramUsernameNormalizer::tryNormalize($username);

                if ($normalized !== null) {
                    $found[$normalized] = true;
                }
            }
        }

        if (preg_match_all('/["\']([A-Za-z0-9._]{1,30})["\']/', $errorMessage, $quotedMatches)) {
            foreach ($quotedMatches[1] as $username) {
                $normalized = InstagramUsernameNormalizer::tryNormalize($username);

                if ($normalized !== null) {
                    $found[$normalized] = true;
                }
            }
        }

        return array_keys($found);
    }
}
