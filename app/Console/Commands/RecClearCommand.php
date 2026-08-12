<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\RecClip;
use App\Models\RecSaveRequest;
use App\Services\RecSessionService;
use Illuminate\Console\Command;

class RecClearCommand extends Command
{
    protected $signature = 'rec:clear
        {game_id : ID do jogo}
        {--force : Confirma sem perguntar}';

    protected $description = 'Apaga clips, SAVEs e arquivos de storage do REC de um jogo';

    public function handle(RecSessionService $recSession): int
    {
        $game = Game::query()->find($this->argument('game_id'));

        if (! $game) {
            $this->error('Jogo não encontrado.');

            return self::FAILURE;
        }

        $clips = RecClip::query()->where('game_id', $game->id)->count();
        $saves = RecSaveRequest::query()->where('game_id', $game->id)->count();

        $this->info("Jogo #{$game->id} · {$clips} clip(s) · {$saves} SAVE(s)");

        if ($clips === 0 && $saves === 0) {
            $recSession->clearGame($game);
            $this->info('Nenhum registro REC no banco. Storage e cache limpos.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apagar somente os dados REC deste jogo?')) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $cleared = $recSession->clearGame($game);

        $this->info("REC limpo: {$cleared['clips']} clip(s) e {$cleared['saves']} SAVE(s) removidos.");

        return self::SUCCESS;
    }
}
