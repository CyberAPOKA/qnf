<?php

namespace App\Console\Commands;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\WeekTeamImageService;
use Illuminate\Console\Command;

class RegenerateWeekTeamImagesCommand extends Command
{
    protected $signature = 'futsal:regenerate-week-team-images
        {game? : ID do jogo (omitir para processar todos os jogos finalizados)}';

    protected $description = 'Regenera os banners do time da semana com as fotos atuais dos jogadores';

    public function handle(WeekTeamImageService $imageService): int
    {
        $gameId = $this->argument('game');

        $query = Game::query()
            ->where('status', GameStatus::DONE)
            ->orderBy('round');

        if ($gameId !== null) {
            $query->whereKey($gameId);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $message = $gameId
                ? "Jogo #{$gameId} não encontrado ou não está finalizado."
                : 'Nenhum jogo finalizado encontrado.';

            $this->warn($message);

            return self::FAILURE;
        }

        $this->info("Processando {$games->count()} jogo(s)...");

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($games as $game) {
            try {
                $paths = $imageService->generate($game);

                if (empty($paths)) {
                    $this->line("  Rodada {$game->round} (#{$game->id}): sem vencedor — ignorado");
                    $skipped++;

                    continue;
                }

                $game->update(['week_team_images' => $paths]);

                $this->info("  Rodada {$game->round} (#{$game->id}): ".count($paths).' imagem(ns) gerada(s)');
                $generated++;
            } catch (\Throwable $e) {
                $this->error("  Rodada {$game->round} (#{$game->id}): {$e->getMessage()}");
                report($e);
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Concluído: {$generated} gerado(s), {$skipped} ignorado(s), {$failed} falha(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
