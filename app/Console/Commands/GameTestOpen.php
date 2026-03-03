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
    protected $signature = 'game:test-open {minutes? : Minutos para adicionar ao horário atual} {--s : Usar horário fixo (horas e minutos)} {--h=0 : Horas} {--m=0 : Minutos}';

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
        $game = $service->getOrCreateThisWeekGame();

        if ($this->option('s')) {
            $hours = (int) $this->option('h');
            $mins = (int) $this->option('m');
            $opensAt = now('America/Sao_Paulo')->startOfDay()->setTime($hours, $mins);
        } else {
            if ($this->argument('minutes') === null) {
                $this->error('Informe os minutos ou use --s --h=HH --m=MM.');
                return Command::FAILURE;
            }

            $minutes = (int) $this->argument('minutes');

            if ($minutes < 0) {
                $this->error('O valor precisa ser maior ou igual a 0.');
                return Command::FAILURE;
            }

            $opensAt = now('America/Sao_Paulo')->addMinutes($minutes);
        }

        $game->update([
            'opens_at' => $opensAt,
            'status' => \App\Enums\GameStatus::SCHEDULED,
        ]);

        $this->info("Game configurado para abrir às {$opensAt->format('H:i:s')}.");

        return Command::SUCCESS;
    }
}
