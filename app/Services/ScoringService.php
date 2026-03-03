<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Models\Game;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScoringService
{
    public const TZ = 'America/Sao_Paulo';

    public function canEnterScores(Game $game, ?CarbonInterface $now = null): bool
    {
        if ($game->status !== GameStatus::DONE) {
            return false;
        }

        $clock = CarbonImmutable::instance($now ?? now(self::TZ))->setTimezone(self::TZ);
        $threshold = CarbonImmutable::instance($game->date)->setTimezone(self::TZ)->setTime(22, 0);

        return $clock->gte($threshold);
    }

    public function saveScores(Game $game, array $scores, bool $force = false, ?CarbonInterface $now = null): void
    {
        if ($game->status !== GameStatus::DONE) {
            throw ValidationException::withMessages([
                'scores' => 'O jogo precisa estar finalizado para registrar o placar.',
            ]);
        }

        if (! $force && ! $this->canEnterScores($game, $now)) {
            throw ValidationException::withMessages([
                'scores' => 'Placar só pode ser registrado após 22h do dia do jogo.',
            ]);
        }

        DB::transaction(function () use ($game, $scores): void {
            $gameId = $game->id;

            // Single CASE UPDATE for all team scores (1 query instead of 3)
            $cases = [];
            $bindings = [];

            foreach ($scores as $color => $score) {
                $cases[] = 'WHEN color = ? THEN ?';
                $bindings[] = $color;
                $bindings[] = (int) $score;
            }

            $bindings[] = $gameId;

            DB::update(
                'UPDATE teams SET score = CASE ' . implode(' ', $cases) . ' ELSE score END WHERE game_id = ?',
                $bindings,
            );

            $this->calculateAndAssignPoints($gameId);
        });
    }

    /**
     * Calcula e atribui pontos aos jogadores do jogo.
     *
     * Queries executadas:
     *   1) SELECT score, color FROM teams (max 3 rows) — determina times vencedores
     *   2) UPDATE game_players SET points = 0 — reset idempotente
     *   3) UPDATE game_players SET points = 1 WHERE user_id IN (
     *        SELECT captain_user_id FROM teams WHERE color IN (vencedores)
     *        UNION ALL
     *        SELECT picked_user_id  FROM draft_picks WHERE team_color IN (vencedores)
     *      ) — atribui pontos via subquery, sem carregar coleções em PHP
     */
    public function calculateAndAssignPoints(int|Game $game): void
    {
        $gameId = $game instanceof Game ? $game->id : $game;

        // 1. Buscar scores (max 3 rows, sempre rápido)
        $scores = DB::table('teams')
            ->where('game_id', $gameId)
            ->whereNotNull('score')
            ->pluck('score', 'color')
            ->map(fn ($score) => (int) $score);

        // 2. Reset idempotente
        DB::table('game_players')
            ->where('game_id', $gameId)
            ->update(['points' => 0]);

        if ($scores->isEmpty()) {
            return;
        }

        $maxScore = $scores->max();
        $winningColors = $scores->filter(fn (int $s) => $s === $maxScore)->keys();

        // Empate triplo → ninguém pontua
        if ($winningColors->count() >= 3) {
            return;
        }

        // 3. Single UPDATE com UNION subquery: capitães + draftados dos times vencedores
        DB::table('game_players')
            ->where('game_id', $gameId)
            ->whereIn('user_id', function ($sub) use ($gameId, $winningColors) {
                $sub->select('captain_user_id')
                    ->from('teams')
                    ->where('game_id', $gameId)
                    ->whereIn('color', $winningColors)
                    ->whereNotNull('captain_user_id')
                    ->unionAll(
                        DB::table('draft_picks')
                            ->select('picked_user_id')
                            ->where('game_id', $gameId)
                            ->whereIn('team_color', $winningColors)
                    );
            })
            ->update(['points' => 1]);
    }

    /**
     * Stats por jogador com regra de pontuação diferenciada.
     *
     * Goleiros: total_points = games_played (1 ponto por partida)
     * Linha:    total_points = SUM(game_players.points) (1 ponto por vitória)
     *
     * @param  array<int>|null  $userIds  Filtrar para jogadores específicos (null = todos)
     * @return Collection<int, object{id: int, name: string, position: string, games_played: int, total_points: int}>
     *         Keyed por user_id
     */
    public function getPlayerStats(?array $userIds = null, bool $includeGuests = false): Collection
    {
        $query = DB::table('game_players')
            ->join('users', 'game_players.user_id', '=', 'users.id')
            ->join('games', 'game_players.game_id', '=', 'games.id')
            ->where('games.status', GameStatus::DONE->value)
            ->select(
                'users.id',
                'users.name',
                'users.position',
                DB::raw('COUNT(game_players.id) as games_played'),
                DB::raw("CASE WHEN users.position = 'goalkeeper' THEN COUNT(game_players.id) ELSE CAST(SUM(game_players.points) AS UNSIGNED) END as total_points"),
            )
            ->groupBy('users.id', 'users.name', 'users.position');

        if (! $includeGuests) {
            $query->where('users.guest', false);
        }

        if ($userIds !== null) {
            $query->whereIn('users.id', $userIds);
        }

        return $query->get()->keyBy('id');
    }

    /**
     * Calcula a sequência de vitórias consecutivas (dos jogos mais recentes)
     * para cada jogador, baseado em game_players.points.
     *
     * @return array<int, int> Keyed por user_id => streak count
     */
    public function getWinStreaks(): array
    {
        $rows = DB::table('game_players')
            ->join('games', 'game_players.game_id', '=', 'games.id')
            ->where('games.status', GameStatus::DONE->value)
            ->orderBy('games.date', 'desc')
            ->select('game_players.user_id', 'game_players.points')
            ->get();

        $streaks = [];

        foreach ($rows->groupBy('user_id') as $userId => $games) {
            $streak = 0;
            foreach ($games as $game) {
                if ((int) $game->points === 1) {
                    $streak++;
                } else {
                    break;
                }
            }
            $streaks[$userId] = $streak;
        }

        return $streaks;
    }

    /**
     * Ranking agregado de todos os jogos finalizados.
     *
     * Delega para getPlayerStats() e aplica ordenação/limite.
     * Ordenação: pontos desc → jogos desc (desempate) → nome asc
     */
    public function getRanking(int $limit = 50, bool $includeGuests = false): array
    {
        $sorted = $this->getPlayerStats(userIds: null, includeGuests: $includeGuests)
            ->sortBy([
                ['total_points', 'desc'],
                ['games_played', 'desc'],
                ['name', 'asc'],
            ])
            ->take($limit)
            ->values();

        $streaks = $this->getWinStreaks();

        // Standard competition ranking (1, 2, 2, 4) — separate for line/goalkeepers
        $linePos = 0;
        $lineRank = 0;
        $lineLast = [null, null];

        $gkPos = 0;
        $gkRank = 0;
        $gkLast = [null, null];

        return $sorted->map(function ($row) use (&$linePos, &$lineRank, &$lineLast, &$gkPos, &$gkRank, &$gkLast, $streaks) {
            $player = (array) $row;
            $pts = (int) $player['total_points'];
            $gp = (int) $player['games_played'];

            if ($player['position'] === 'goalkeeper') {
                $gkPos++;
                if ($pts !== $gkLast[0] || $gp !== $gkLast[1]) {
                    $gkRank = $gkPos;
                    $gkLast = [$pts, $gp];
                }
                $player['rank'] = $gkRank;
            } else {
                $linePos++;
                if ($pts !== $lineLast[0] || $gp !== $lineLast[1]) {
                    $lineRank = $linePos;
                    $lineLast = [$pts, $gp];
                }
                $player['rank'] = $lineRank;
            }

            $player['win_streak'] = $streaks[$player['id']] ?? 0;

            return $player;
        })->all();
    }
}
