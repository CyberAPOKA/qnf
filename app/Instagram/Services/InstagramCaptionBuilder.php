<?php

namespace App\Instagram\Services;

use App\Models\Game;

class InstagramCaptionBuilder
{
    /**
     * @param  list<array{color?: string, label?: string, points?: int|float, goals?: int|float}>  $teamScores
     * @param  list<string>  $usernames
     */
    public function buildWeeklyTeamsCaption(Game $game, array $teamScores, array $usernames): string
    {
        $lines = [
            'Times da semana',
        ];

        $round = $game->round;

        if ($round !== null && $round !== '') {
            $lines[] = 'Rodada '.$round;
        } elseif ($game->date) {
            $lines[] = $game->date->format('d/m/Y');
        }

        $lines[] = '';

        foreach ($teamScores as $team) {
            $label = trim((string) ($team['label'] ?? ''));

            if ($label === '' && isset($team['color'])) {
                $label = 'Time '.ucfirst((string) $team['color']);
            }

            if ($label === '') {
                $label = 'Time';
            }

            $points = (int) ($team['points'] ?? 0);
            $lines[] = sprintf('%s: %d %s', $label, $points, $this->pointsWord($points));
        }

        $mentions = $this->formatMentions($usernames);

        if ($mentions !== '') {
            $lines[] = '';
            $lines[] = $mentions;
        }

        $hashtags = $this->formatHashtags();

        if ($hashtags !== '') {
            $lines[] = '';
            $lines[] = $hashtags;
        }

        $caption = trim(implode("\n", $lines));
        $limit = max(1, (int) config('instagram.limits.caption_length', 2200));

        if (mb_strlen($caption) <= $limit) {
            return $caption;
        }

        return rtrim(mb_substr($caption, 0, $limit - 1)).'…';
    }

    private function pointsWord(int $points): string
    {
        return abs($points) === 1 ? 'ponto' : 'pontos';
    }

    /**
     * @param  list<string>  $usernames
     */
    private function formatMentions(array $usernames): string
    {
        $mentions = [];

        foreach ($usernames as $username) {
            $clean = ltrim(trim((string) $username), '@');

            if ($clean === '') {
                continue;
            }

            $mentions[] = '@'.$clean;
        }

        return implode(' ', array_values(array_unique($mentions)));
    }

    private function formatHashtags(): string
    {
        $configured = config('instagram.caption_hashtags', []);
        $limit = max(0, (int) config('instagram.limits.hashtags', 30));

        if (! is_array($configured) || $limit === 0) {
            return '';
        }

        $tags = [];

        foreach ($configured as $tag) {
            $value = trim((string) $tag);

            if ($value === '') {
                continue;
            }

            if (! str_starts_with($value, '#')) {
                $value = '#'.$value;
            }

            $tags[] = $value;

            if (count($tags) >= $limit) {
                break;
            }
        }

        return implode(' ', $tags);
    }
}
