<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\ScoringService;
use Illuminate\Console\Command;

class SaveGameScoresCommand extends Command
{
    protected $signature = 'futsal:save-scores
        {game_id : ID do jogo}
        {green : Placar do time verde}
        {yellow : Placar do time amarelo}
        {blue : Placar do time azul}
        {--force : Ignora restrição de horário}';

    protected $description = 'Registra o placar das equipes de um jogo';

    public function handle(ScoringService $scoringService): int
    {
        $game = Game::findOrFail($this->argument('game_id'));

        $scores = [
            'green' => (int) $this->argument('green'),
            'yellow' => (int) $this->argument('yellow'),
            'blue' => (int) $this->argument('blue'),
        ];

        try {
            $scoringService->saveScores($game, $scores, force: $this->option('force'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode("\n"));

            return self::FAILURE;
        }

        $this->info("Placar registrado: Verde {$scores['green']} x Amarelo {$scores['yellow']} x Azul {$scores['blue']}");
        $this->info('Pontos dos jogadores calculados com sucesso.');

        return self::SUCCESS;
    }
}
