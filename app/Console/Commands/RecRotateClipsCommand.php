<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\RecClip;
use App\Services\RecClipNormalizeService;
use App\Support\PublicStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecRotateClipsCommand extends Command
{
    protected $signature = 'rec:rotate-clips
        {game_id : ID do jogo}
        {--camera=B1 : Tag da câmera (A1, A2, B1 ou B2)}
        {--dir=cw : Sentido: cw (90° horário), ccw (90° anti-horário) ou 180}
        {--clip= : Rotacionar só este clip id (útil para testar um vídeo)}
        {--last : Rotacionar só o clip mais recente desta câmera}
        {--dry-run : Lista o que seria alterado, sem gravar}
        {--force : Confirma sem perguntar}';

    protected $description = 'Rotaciona clips REC de uma câmera para horizontal (90°)';

    public function handle(RecClipNormalizeService $normalize): int
    {
        $game = Game::query()->find($this->argument('game_id'));

        if (! $game) {
            $this->error('Jogo não encontrado.');

            return self::FAILURE;
        }

        $camera = strtoupper((string) $this->option('camera'));
        $direction = strtolower((string) $this->option('dir'));
        $clipId = $this->option('clip');
        $onlyLast = (bool) $this->option('last');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($camera, ['A1', 'A2', 'B1', 'B2'], true)) {
            $this->error('Câmera inválida. Use A1, A2, B1 ou B2.');

            return self::FAILURE;
        }

        if (! in_array($direction, ['cw', 'ccw', '180'], true)) {
            $this->error('Direção inválida. Use --dir=cw, --dir=ccw ou --dir=180.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $normalize->ffmpegAvailable()) {
            $this->error('ffmpeg não encontrado neste ambiente.');

            return self::FAILURE;
        }

        $query = RecClip::query()
            ->where('game_id', $game->id)
            ->where('camera_tag', $camera);

        if ($clipId !== null && $clipId !== '') {
            $query->whereKey($clipId);
        } elseif ($onlyLast) {
            $query->latest('id')->limit(1);
        } else {
            $query->orderBy('id');
        }

        $clips = $query->get();

        if ($clips->isEmpty()) {
            $this->warn("Nenhum clip {$camera} encontrado no jogo #{$game->id}.");

            return self::SUCCESS;
        }

        $label = match ($direction) {
            'ccw' => '90° anti-horário',
            '180' => '180°',
            default => '90° horário',
        };
        $this->info("Jogo #{$game->id} · câmera {$camera} · {$clips->count()} clip(s) · {$label}");

        $disk = Storage::disk('public');
        $fallbackUrl = (string) config('filesystems.disks.public.fallback_url');
        $toRotate = [];

        foreach ($clips as $clip) {
            $existsLocal = $disk->exists($clip->file_path);
            $absolute = $existsLocal ? $disk->path($clip->file_path) : null;
            $size = $absolute && is_file($absolute) ? $normalize->probeVideoSize($absolute) : null;
            $status = 'rotacionar';

            if ($existsLocal && $direction !== '180' && $size && $size['width'] >= $size['height']) {
                $status = 'já horizontal — pular';
            } elseif ($existsLocal) {
                $toRotate[] = $clip;
            } elseif ($fallbackUrl) {
                $status = $dryRun
                    ? 'remoto — será baixado e rotacionado'
                    : 'baixando do fallback';
                $toRotate[] = $clip;
            } else {
                $status = 'arquivo ausente (sem STORAGE_FALLBACK_URL)';
            }

            $dimensions = $size ? "{$size['width']}x{$size['height']}" : '?x?';
            $this->line("  #{$clip->id} {$dimensions} {$clip->file_path} → {$status}");
        }

        if (! $dryRun && $toRotate !== [] && $fallbackUrl) {
            $this->comment('Arquivos ausentes no localhost serão baixados de '.$fallbackUrl);
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry-run: nenhum arquivo foi alterado.');
            $this->info('Para aplicar: php artisan rec:rotate-clips '.$game->id.' --camera='.$camera.' --force');
            $this->line('Para testar um só: php artisan rec:rotate-clips '.$game->id.' --camera='.$camera.' --clip='.$clips->first()->id.' --force');

            return self::SUCCESS;
        }

        if ($toRotate === []) {
            $this->info('Nada para rotacionar.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Rotacionar '.count($toRotate).' arquivo(s) no disco?')) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($toRotate as $clip) {
            if (! Storage::disk('public')->exists($clip->file_path)) {
                $this->line("  #{$clip->id} baixando...");
                if (! PublicStorage::localPath($clip->file_path)) {
                    $this->error("  #{$clip->id} falhou ao baixar");
                    $failed++;

                    continue;
                }
            }

            if ($normalize->rotate($clip->file_path, $direction)) {
                $this->info("  #{$clip->id} ok");
                $ok++;
            } else {
                $this->error("  #{$clip->id} falhou");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Concluído: {$ok} rotacionado(s), {$failed} falha(s).");
        $this->line('Recarregue a página REC para ver o resultado.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
