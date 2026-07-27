<?php

namespace App\Console\Commands;

use App\Instagram\Services\InstagramPublishingService;
use App\Models\Game;
use Illuminate\Console\Command;
use Throwable;

class InstagramPublishTestCommand extends Command
{
    protected $signature = 'instagram:publish-test
        {--type=story : story|carousel}
        {--dry-run : Executa em modo dry-run}
        {--game= : ID do jogo}';

    protected $description = 'Enfileira uma publicação de teste no Instagram';

    public function handle(InstagramPublishingService $publishingService): int
    {
        $type = strtolower((string) $this->option('type'));
        $dryRun = (bool) $this->option('dry-run') || (bool) config('instagram.dry_run');
        $gameId = $this->option('game');

        if (! in_array($type, ['story', 'carousel'], true)) {
            $this->error('Tipo inválido. Use story ou carousel.');

            return self::FAILURE;
        }

        if ($dryRun) {
            config()->set('instagram.dry_run', true);
            $this->warn('Modo dry-run ativo.');
        }

        if (! $gameId) {
            if ($dryRun) {
                $this->info('Dry-run OK: informe --game=ID para enfileirar conteúdo real/teste.');

                return self::SUCCESS;
            }

            $this->error('O parâmetro --game=ID é obrigatório para publicar conteúdo.');

            return self::FAILURE;
        }

        $game = Game::query()->find($gameId);

        if (! $game) {
            $this->error('Jogo não encontrado: '.$gameId);

            return self::FAILURE;
        }

        if (! config('instagram.enabled') && ! $dryRun) {
            $this->warn('instagram.enabled=false; habilite INSTAGRAM_ENABLED ou use --dry-run.');
        }

        try {
            if ($type === 'story') {
                $publication = $publishingService->queueDraftStory($game);

                if (! $publication) {
                    $this->warn('Nenhuma publicação criada (instagram.enabled=false?).');

                    return self::SUCCESS;
                }

                $this->info('Draft story enfileirada: '.$publication->uuid);
            } else {
                $publications = $publishingService->queueMatchResultPublications($game);

                if ($publications === []) {
                    $this->warn('Nenhuma publicação criada (instagram.enabled=false?).');

                    return self::SUCCESS;
                }

                $this->info('Publicações de resultado enfileiradas: '.count($publications));
                foreach ($publications as $publication) {
                    $this->line(sprintf(
                        '- %s (%s)',
                        $publication->uuid,
                        $publication->publication_type?->value ?? 'n/a'
                    ));
                }
            }
        } catch (Throwable $e) {
            $this->error('Falha ao enfileirar publicação de teste: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
