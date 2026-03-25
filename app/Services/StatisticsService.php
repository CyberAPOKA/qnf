<?php

namespace App\Services;

use App\Enums\GameStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * Retorna todos os pares de um usuário com count >= minCount,
     * ordenados por times_together DESC.
     *
     * @return array<int, array{name: string, count: int}>
     */
    private function getPartnersForUser(int $userId, Collection $allPairs, Collection $userNames, int $minCount = 1): array
    {
        return $allPairs
            ->filter(fn ($pair) => ((int) $pair->user_a === $userId || (int) $pair->user_b === $userId)
                && (int) $pair->times_together >= $minCount)
            ->map(function ($pair) use ($userId, $userNames) {
                $partnerId = (int) $pair->user_a === $userId ? (int) $pair->user_b : (int) $pair->user_a;

                return [
                    'name' => $userNames->get($partnerId, '?'),
                    'count' => (int) $pair->times_together,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Estatísticas de duplas para um jogador específico (apenas linha).
     *
     * @return array{played_with: array, won_with: array}
     */
    public function getPlayerStatistics(int $userId): array
    {
        $playedPairs = $this->getPairCounts('played');
        $wonPairs = $this->getPairCounts('won');

        $userNames = DB::table('users')->pluck('name', 'id');

        return [
            'played_with' => $this->getPartnersForUser($userId, $playedPairs, $userNames, 2),
            'won_with' => $this->getPartnersForUser($userId, $wonPairs, $userNames, 1),
        ];
    }

    /**
     * Retorna os pares brutos (sem agrupar por usuário).
     */
    private function getPairCounts(string $type): Collection
    {
        $wonJoin = $type === 'won'
            ? "INNER JOIN game_players gp_a ON gp_a.game_id = a.game_id AND gp_a.user_id = a.user_id AND gp_a.points = 1
               INNER JOIN game_players gp_b ON gp_b.game_id = b.game_id AND gp_b.user_id = b.user_id AND gp_b.points = 1"
            : '';

        $pairs = DB::select("
            WITH team_members AS (
                SELECT t.game_id, t.color, t.captain_user_id AS user_id
                FROM teams t
                INNER JOIN games g ON g.id = t.game_id
                WHERE g.status = ?
                  AND t.captain_user_id IS NOT NULL
                UNION ALL
                SELECT dp.game_id, dp.team_color AS color, dp.picked_user_id AS user_id
                FROM draft_picks dp
                INNER JOIN games g ON g.id = dp.game_id
                WHERE g.status = ?
            ),
            pair_counts AS (
                SELECT
                    a.user_id AS user_a,
                    b.user_id AS user_b,
                    COUNT(*) AS times_together
                FROM team_members a
                INNER JOIN team_members b
                    ON a.game_id = b.game_id
                   AND a.color = b.color
                   AND a.user_id < b.user_id
                {$wonJoin}
                GROUP BY a.user_id, b.user_id
            )
            SELECT * FROM pair_counts
            ORDER BY times_together DESC
        ", [GameStatus::DONE->value, GameStatus::DONE->value]);

        return collect($pairs);
    }
}
