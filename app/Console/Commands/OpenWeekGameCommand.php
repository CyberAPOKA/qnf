<?php

namespace App\Console\Commands;

use App\Services\GameService;
use Illuminate\Console\Command;

class OpenWeekGameCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'futsal:open-week-game {--force : Força abrir o jogo da semana agora}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Abre ou cria a partida semanal de futsal';

    /**
     * Execute the console command.
     */
    public function handle(GameService $gameService): int
    {
        $game = $this->option('force')
            ? $gameService->forceOpenThisWeekGame()
            : $gameService->openGameIfNeeded();

        if ($game) {
            $this->info("Jogo da semana pronto (status: {$game->status->value}).");
        } else {
            $this->line('Sem ação necessária agora.');
        }

        return self::SUCCESS;
    }
}
