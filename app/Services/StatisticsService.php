<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\Position;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * Estatísticas de duplas e confrontos para um jogador específico.
     *
     * @return array{partners: array<int, array{
     *     id: int,
     *     name: string,
     *     is_goalkeeper: bool,
     *     games_together: int,
     *     games_against: int,
     *     wins_together: int,
     *     draws_together: int,
     *     tie2_together: int,
     *     losses_together: int,
     *     wins_against: int,
     *     draws_against: int,
     *     tie2_against: int,
     *     losses_against: int
     * }>}
     */
    public function getPlayerStatistics(int $userId): array
    {
        $stats = $this->getPartnerStatsForUser($userId);

        $users = DB::table('users')
            ->select('id', 'name', 'position')
            ->get()
            ->keyBy('id');

        $partners = $stats
            ->map(function ($row) use ($users) {
                $user = $users->get((int) $row->partner_id);
                if (! $user) {
                    return null;
                }

                return [
                    'id' => (int) $row->partner_id,
                    'name' => $user->name,
                    'is_goalkeeper' => $user->position === Position::GOALKEEPER->value,
                    'games_together' => (int) $row->games_together,
                    'games_against' => (int) $row->games_against,
                    'wins_together' => (int) $row->wins_together,
                    'draws_together' => (int) $row->draws_together,
                    'tie2_together' => (int) $row->tie2_together,
                    'losses_together' => (int) $row->losses_together,
                    'wins_against' => (int) $row->wins_against,
                    'draws_against' => (int) $row->draws_against,
                    'tie2_against' => (int) $row->tie2_against,
                    'losses_against' => (int) $row->losses_against,
                ];
            })
            ->filter()
            ->filter(fn (array $partner) => $partner['games_together'] >= 2 || $partner['games_against'] >= 1)
            ->sortByDesc('games_together')
            ->values()
            ->all();

        return [
            'partners' => $partners,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function getPartnerStatsForUser(int $userId): Collection
    {
        $rows = DB::select('
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
            winner_teams AS (
                SELECT t.game_id, COUNT(*) AS winning_teams_count
                FROM teams t
                INNER JOIN (
                    SELECT game_id, MAX(score) AS max_score
                    FROM teams
                    WHERE score IS NOT NULL
                    GROUP BY game_id
                ) mx ON mx.game_id = t.game_id AND t.score = mx.max_score
                GROUP BY t.game_id
            ),
            winning_colors AS (
                SELECT t.game_id, t.color
                FROM teams t
                INNER JOIN (
                    SELECT game_id, MAX(score) AS max_score
                    FROM teams
                    WHERE score IS NOT NULL
                    GROUP BY game_id
                ) mx ON mx.game_id = t.game_id AND t.score = mx.max_score
            )
            SELECT
                partner.user_id AS partner_id,
                SUM(CASE WHEN me.color = partner.color THEN 1 ELSE 0 END) AS games_together,
                SUM(CASE WHEN me.color <> partner.color THEN 1 ELSE 0 END) AS games_against,

                SUM(CASE
                    WHEN me.color = partner.color
                     AND wme.color IS NOT NULL
                     AND wt.winning_teams_count = 1
                    THEN 1 ELSE 0
                END) AS wins_together,
                SUM(CASE
                    WHEN me.color = partner.color
                     AND wme.color IS NOT NULL
                     AND wt.winning_teams_count >= 2
                    THEN 1 ELSE 0
                END) AS draws_together,
                SUM(CASE
                    WHEN me.color = partner.color
                     AND wme.color IS NOT NULL
                     AND wt.winning_teams_count = 2
                    THEN 1 ELSE 0
                END) AS tie2_together,
                SUM(CASE
                    WHEN me.color = partner.color
                     AND wme.color IS NULL
                    THEN 1 ELSE 0
                END) AS losses_together,

                SUM(CASE
                    WHEN me.color <> partner.color
                     AND wme.color IS NOT NULL
                     AND wpartner.color IS NULL
                    THEN 1 ELSE 0
                END) AS wins_against,
                SUM(CASE
                    WHEN me.color <> partner.color
                     AND wme.color IS NOT NULL
                     AND wpartner.color IS NOT NULL
                    THEN 1 ELSE 0
                END) AS draws_against,
                SUM(CASE
                    WHEN me.color <> partner.color
                     AND wme.color IS NOT NULL
                     AND wpartner.color IS NOT NULL
                    THEN 1 ELSE 0
                END) AS tie2_against,
                SUM(CASE
                    WHEN me.color <> partner.color
                     AND wme.color IS NULL
                     AND wpartner.color IS NOT NULL
                    THEN 1 ELSE 0
                END) AS losses_against
            FROM team_members me
            INNER JOIN team_members partner
                ON me.game_id = partner.game_id
               AND me.user_id != partner.user_id
            INNER JOIN winner_teams wt
                ON wt.game_id = me.game_id
            LEFT JOIN winning_colors wme
                ON wme.game_id = me.game_id
               AND wme.color = me.color
            LEFT JOIN winning_colors wpartner
                ON wpartner.game_id = partner.game_id
               AND wpartner.color = partner.color
            WHERE me.user_id = ?
            GROUP BY partner.user_id
        ', [GameStatus::DONE->value, GameStatus::DONE->value, $userId]);

        return collect($rows);
    }
}
