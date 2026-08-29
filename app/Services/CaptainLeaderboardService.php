<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CaptainLeaderboardService
{
    /**
     * Top capitães em todas as rodadas que já têm capitão sorteado,
     * incluindo a rodada atual (drafting/drafted).
     *
     * @return list<array{id: int, name: string, count: int, rounds: list<int>}>
     */
    public function top(int $limit = 10): array
    {
        $rows = DB::table('teams')
            ->join('games', 'games.id', '=', 'teams.game_id')
            ->join('users', 'users.id', '=', 'teams.captain_user_id')
            ->whereNotNull('teams.captain_user_id')
            ->whereNotNull('games.round')
            ->whereNull('users.deleted_at')
            ->select('users.id', 'users.name', 'games.round')
            ->get();

        return $rows
            ->groupBy(fn ($row) => (int) $row->id)
            ->map(function ($group) {
                $rounds = $group
                    ->pluck('round')
                    ->map(fn ($round) => (int) $round)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $first = $group->first();

                return [
                    'id' => (int) $first->id,
                    'name' => $first->name,
                    'count' => count($rounds),
                    'rounds' => $rounds,
                ];
            })
            ->sort(fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: $a['name'] <=> $b['name'])
            ->take($limit)
            ->values()
            ->all();
    }
}
