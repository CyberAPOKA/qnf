<?php

namespace App\Console\Commands;

use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Services\InstagramPublishingService;
use App\Models\InstagramPublication;
use Illuminate\Console\Command;
use Throwable;

class InstagramRetryFailedCommand extends Command
{
    protected $signature = 'instagram:retry-failed {publication? : UUID ou ID da publicação}';

    protected $description = 'Reprocessa publicações Instagram com status Failed';

    public function handle(InstagramPublishingService $publishingService): int
    {
        $publicationArg = $this->argument('publication');

        if ($publicationArg !== null && $publicationArg !== '') {
            return $this->retryOne($publishingService, (string) $publicationArg);
        }

        $publications = InstagramPublication::query()
            ->where('status', InstagramPublicationStatus::Failed)
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($publications->isEmpty()) {
            $this->info('Nenhuma publicação Failed encontrada.');

            return self::SUCCESS;
        }

        $retried = 0;

        foreach ($publications as $publication) {
            try {
                $publishingService->retry($publication);
                $retried++;
                $this->line(sprintf(
                    'Reenfileirada: %s (%s)',
                    $publication->uuid,
                    $publication->publication_type?->value ?? 'n/a'
                ));
            } catch (Throwable $e) {
                $this->error(sprintf(
                    'Falha ao reenfileirar %s: %s',
                    $publication->uuid,
                    $e->getMessage()
                ));
            }
        }

        $this->info("Publicações reenfileiradas: {$retried}/{$publications->count()}");

        return self::SUCCESS;
    }

    private function retryOne(InstagramPublishingService $publishingService, string $publicationArg): int
    {
        $publication = InstagramPublication::query()
            ->when(
                ctype_digit($publicationArg),
                fn ($query) => $query->where('id', (int) $publicationArg),
                fn ($query) => $query->where('uuid', $publicationArg),
            )
            ->first();

        if (! $publication) {
            $this->error('Publicação não encontrada: '.$publicationArg);

            return self::FAILURE;
        }

        try {
            $publishingService->retry($publication);
        } catch (Throwable $e) {
            $this->error('Falha ao reenfileirar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Publicação reenfileirada: '.$publication->uuid);
        $this->line('Tipo: '.($publication->publication_type?->value ?? 'n/a'));
        $this->line('Status anterior tratado como retry.');

        return self::SUCCESS;
    }
}
