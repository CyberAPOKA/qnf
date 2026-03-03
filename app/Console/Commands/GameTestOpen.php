<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GameService;

class GameTestOpen extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:test-open {minutes : Minutos para adicionar ao horário atual}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configura o jogo para abrir em X minutos a partir de agora';

    /**
     * Execute the console command.
     */
    public function handle(GameService $service)
    {
        $minutes = (int) $this->argument('minutes');

        if ($minutes < 0) {
            $this->error('O valor precisa ser maior ou igual a 0.');
            return Command::FAILURE;
        }

        $game = $service->getOrCreateThisWeekGame();
        $opensAt = now('America/Sao_Paulo')->addMinutes($minutes);

        $game->update([
            'opens_at' => $opensAt,
            'status' => \App\Enums\GameStatus::SCHEDULED,
        ]);

        $this->info("Game configurado para abrir em {$minutes} minuto(s) ({$opensAt->format('H:i:s')}).");

        return Command::SUCCESS;
    }
}
