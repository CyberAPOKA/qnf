<?php

namespace App\Console\Commands;

use App\Services\GameService;
use Illuminate\Console\Command;

class CreateWeekGameCommand extends Command
{
    protected $signature = 'futsal:create-week-game {--force : Cria o jogo mesmo antes do horário agendado}';

    protected $description = 'Cria a partida semanal a partir do agendamento do mercado';

    public function handle(GameService $gameService): int
    {
        $game = $gameService->createScheduledGameIfNeeded(force: (bool) $this->option('force'));

        if ($game) {
            $this->info("Jogo da rodada {$game->round} criado (status: {$game->status->value}).");
        } else {
            $this->line('Sem agendamento pendente para criar agora.');
        }

        return self::SUCCESS;
    }
}
