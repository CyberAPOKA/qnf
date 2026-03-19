<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

class GamePredictionService
{
    /**
     * Pesos da fórmula de previsão.
     */
    private const WEIGHT_ABILITY = 0.70;
    private const WEIGHT_WINS = 0.20;
    private const WEIGHT_ROUND_SCORES = 0.10;

    /**
     * Número de partidas por rodada.
     */
    private const MATCHES_PER_ROUND = 7;

    /**
     * Número de simulações Monte Carlo para estabilizar a previsão.
     */
    private const SIMULATIONS = 10000;

    public function __construct(
        private readonly RoundWinsRankingService $roundWinsRankingService,
        private readonly ScoringService $scoringService,
    ) {}

    /**
     * Gera a previsão de placar para um jogo que já tem times definidos (DRAFTED ou DONE).
     *
     * Simula MATCHES_PER_ROUND partidas entre os 3 times, onde cada partida
     * envolve 2 dos 3 times (rodízio: GxY, GxB, YxB, GxY, GxB, YxB, GxY).
     * A probabilidade de vitória em cada partida é baseada na força composta do time.
     *
     * @return array|null
     */
    public function predict(Game $game): ?array
    {
        if (! in_array($game->status, [GameStatus::DRAFTED, GameStatus::DONE])) {
            return null;
        }

        $game->loadMissing(['teams', 'draftPicks']);

        if ($game->teams->isEmpty()) {
            return null;
        }

        // Buscar dados de ranking de vitórias (normalizado)
        $winsRanking = collect($this->roundWinsRankingService->getRanking(includeGuests: true))
            ->keyBy('id');

        // Buscar dados de pontuação por rodada (normalizado)
        $playerStats = $this->scoringService->getPlayerStats(includeGuests: true);

        // Buscar habilidades dos jogadores
        $allPlayerIds = [];
        foreach ($game->teams as $team) {
            if ($team->captain_user_id) {
                $allPlayerIds[] = $team->captain_user_id;
            }
        }
        foreach ($game->draftPicks as $pick) {
            $allPlayerIds[] = $pick->picked_user_id;
        }

        $abilities = DB::table('users')
            ->whereIn('id', $allPlayerIds)
            ->pluck('ability', 'id')
            ->map(fn ($v) => (int) $v);

        // Calcular max values para normalização
        $maxWins = $winsRanking->max('total_score') ?: 1;
        $maxPoints = $playerStats->max('total_points') ?: 1;

        $teamLabels = [
            'green' => 'Verde',
            'yellow' => 'Amarelo',
            'blue' => 'Azul',
        ];

        // Calcular força composta de cada time
        $teamStrengths = [];

        foreach ($game->teams as $team) {
            $color = $team->color->value ?? $team->color;
            $playerIds = [];

            if ($team->captain_user_id) {
                $playerIds[] = $team->captain_user_id;
            }

            foreach ($game->draftPicks->where('team_color', $color) as $pick) {
                $playerIds[] = $pick->picked_user_id;
            }

            if (empty($playerIds)) {
                continue;
            }

            $totalComposite = 0;

            foreach ($playerIds as $playerId) {
                $ability = $abilities->get($playerId, 5);
                $normalizedAbility = $ability / 10;

                $wins = $winsRanking->get($playerId);
                $normalizedWins = $wins ? ($wins['total_score'] / $maxWins) : 0;

                $stats = $playerStats->get($playerId);
                $normalizedPoints = $stats ? ($stats->total_points / $maxPoints) : 0;

                $composite = (self::WEIGHT_ABILITY * $normalizedAbility)
                    + (self::WEIGHT_WINS * $normalizedWins)
                    + (self::WEIGHT_ROUND_SCORES * $normalizedPoints);

                $totalComposite += $composite;
            }

            $teamStrengths[$color] = $totalComposite;
        }

        $colors = array_keys($teamStrengths);

        if (count($colors) < 3) {
            return null;
        }

        // Rodízio de confrontos: cada partida é entre 2 dos 3 times
        // Padrão: AB, AC, BC, AB, AC, BC, AB (7 partidas)
        $matchups = [];
        $pairs = [
            [$colors[0], $colors[1]],
            [$colors[0], $colors[2]],
            [$colors[1], $colors[2]],
        ];
        for ($i = 0; $i < self::MATCHES_PER_ROUND; $i++) {
            $matchups[] = $pairs[$i % 3];
        }

        // Simulação Monte Carlo para prever placar
        $totalScores = array_fill_keys($colors, 0);

        for ($sim = 0; $sim < self::SIMULATIONS; $sim++) {
            $simScores = array_fill_keys($colors, 0);

            foreach ($matchups as [$teamA, $teamB]) {
                $strengthA = $teamStrengths[$teamA];
                $strengthB = $teamStrengths[$teamB];
                $total = $strengthA + $strengthB;

                if ($total <= 0) {
                    continue;
                }

                // Probabilidade de A vencer
                $probA = $strengthA / $total;
                $random = mt_rand() / mt_getrandmax();

                if ($random < $probA) {
                    $simScores[$teamA]++;
                } else {
                    $simScores[$teamB]++;
                }
            }

            foreach ($colors as $color) {
                $totalScores[$color] += $simScores[$color];
            }
        }

        // Calcular placar médio previsto
        $predictedScores = [];
        foreach ($colors as $color) {
            $predictedScores[$color] = round($totalScores[$color] / self::SIMULATIONS, 1);
        }

        // Montar resultado
        $teams = [];
        $totalMatches = self::MATCHES_PER_ROUND;

        foreach ($colors as $color) {
            $teams[] = [
                'color' => $color,
                'label' => $teamLabels[$color] ?? $color,
                'predicted_score' => $predictedScores[$color],
                'strength' => round($teamStrengths[$color], 3),
                'win_probability' => round(($predictedScores[$color] / $totalMatches) * 100, 1),
            ];
        }

        // Ordenar por placar previsto
        usort($teams, fn ($a, $b) => $b['predicted_score'] <=> $a['predicted_score']);

        return [
            'teams' => $teams,
            'total_matches' => $totalMatches,
            'predicted_winner' => $teams[0]['color'],
            'predicted_winner_label' => $teams[0]['label'],
        ];
    }
}
