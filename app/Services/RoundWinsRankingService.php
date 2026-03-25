<?php

namespace App\Services;

use App\Enums\GameStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RoundWinsRankingService
{
    /**
     * Ranking baseado na soma dos pontos do time de cada jogador em cada rodada.
     *
     * Para cada jogador, encontra o time em que jogou (capitão ou draftado),
     * pega o score (teams.score) desse time na rodada, e soma todos os scores.
     *
     * Ex: Christian Steffens jogou 7 rodadas nos times que fizeram
     *     2, 3, 3, 3, 2, 3, 0 → total_score = 16, média = 2.3
     */
    public function getRanking(bool $includeGuests = false): array
    {
        // Subquery: para cada jogador em cada jogo, retorna o score do time dele
        // Um jogador pode ser capitão (teams.captain_user_id) ou draftado (draft_picks.picked_user_id)
        $playerTeamScores = DB::query()
            ->fromSub(function ($query) {
                // Capitães
                $captains = DB::table('teams')
                    ->join('games', 'teams.game_id', '=', 'games.id')
                    ->where('games.status', GameStatus::DONE->value)
                    ->whereNotNull('teams.captain_user_id')
                    ->whereNotNull('teams.score')
                    ->select(
                        'teams.captain_user_id as user_id',
                        'teams.game_id',
                        'teams.score',
                    );

                // Draftados
                $query->from('draft_picks')
                    ->join('teams', function ($join) {
                        $join->on('draft_picks.game_id', '=', 'teams.game_id')
                            ->on('draft_picks.team_color', '=', 'teams.color');
                    })
                    ->join('games', 'draft_picks.game_id', '=', 'games.id')
                    ->where('games.status', GameStatus::DONE->value)
                    ->whereNotNull('teams.score')
                    ->select(
                        'draft_picks.picked_user_id as user_id',
                        'draft_picks.game_id',
                        'teams.score',
                    )
                    ->unionAll($captains);
            }, 'player_scores')
            ->join('users', 'player_scores.user_id', '=', 'users.id')
            ->where('users.position', '!=', 'goalkeeper')
            ->select(
                'users.id',
                'users.name',
                'users.position',
                'users.photo_front',
                DB::raw('CAST(SUM(player_scores.score) AS UNSIGNED) as total_score'),
                DB::raw('COUNT(DISTINCT player_scores.game_id) as games_played'),
                DB::raw('ROUND(SUM(player_scores.score) / COUNT(DISTINCT player_scores.game_id), 1) as avg_score'),
            )
            ->groupBy('users.id', 'users.name', 'users.position', 'users.photo_front');

        if (! $includeGuests) {
            $playerTeamScores->where('users.guest', false);
        }

        $rows = $playerTeamScores
            ->orderByDesc('total_score')
            ->orderByDesc('avg_score')
            ->orderBy('name')
            ->get();

        // Competition ranking (1, 2, 2, 4)
        $pos = 0;
        $rank = 0;
        $lastScore = null;
        $lastAvg = null;

        return $rows->map(function ($row) use (&$pos, &$rank, &$lastScore, &$lastAvg) {
            $pos++;
            $score = (int) $row->total_score;
            $avg = (float) $row->avg_score;

            if ($score !== $lastScore || $avg !== $lastAvg) {
                $rank = $pos;
                $lastScore = $score;
                $lastAvg = $avg;
            }

            return [
                'id' => $row->id,
                'name' => $row->name,
                'position' => $row->position,
                'photo_front' => $row->photo_front ? Storage::disk('public')->url($row->photo_front) : null,
                'initial' => mb_strtoupper(mb_substr($row->name, 0, 1)),
                'total_score' => $score,
                'games_played' => (int) $row->games_played,
                'avg_score' => $avg,
                'rank' => $rank,
            ];
        })->all();
    }
}
