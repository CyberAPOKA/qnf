<?php

namespace App\Instagram\Services;

use App\Instagram\Exceptions\InstagramAssetException;
use App\Models\Game;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\File;

class InstagramDraftStoryRenderer
{
    private const WIDTH = 1080;

    private const HEIGHT = 1920;

    public function render(Game $game, string $destAbsolute, ?string $sourceAbsolutePath = null): string
    {
        $source = $sourceAbsolutePath;

        if (! $source || ! is_file($source)) {
            $source = $this->resolveExistingLineupsOrCaptains($game);
        }

        if ($source && is_file($source)) {
            return $this->renderFromLineupsImage($source, $game, $destAbsolute);
        }

        return $this->renderFallback($game, $destAbsolute);
    }

    public function renderFromLineupsImage(string $lineupsAbsolutePath, Game $game, string $destAbsolute): string
    {
        if (! is_file($lineupsAbsolutePath)) {
            throw new InstagramAssetException('Imagem de lineups não encontrada para o story do draft.');
        }

        $info = @getimagesize($lineupsAbsolutePath);
        if (! $info) {
            throw new InstagramAssetException('Imagem de lineups inválida para o story do draft.');
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($lineupsAbsolutePath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($lineupsAbsolutePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($lineupsAbsolutePath) : null,
            default => null,
        };

        if (! $src) {
            throw new InstagramAssetException('Não foi possível abrir a imagem de lineups.');
        }

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);

        $bg = imagecolorallocate($canvas, 10, 10, 14);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $safeTop = 220;
        $safeBottom = 160;
        $availableH = self::HEIGHT - $safeTop - $safeBottom;
        $scale = min(self::WIDTH / $srcW, $availableH / $srcH);
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);
        $dstX = (int) ((self::WIDTH - $newW) / 2);
        $dstY = $safeTop + (int) (($availableH - $newH) / 2);

        imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($src);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $accent = imagecolorallocate($canvas, 234, 179, 8);
        $muted = imagecolorallocate($canvas, 170, 170, 180);

        $round = $game->round ?? $game->id;
        $this->drawCenteredText($canvas, 'DRAFT FINALIZADO', 48, 120, $accent, true);
        $this->drawCenteredText($canvas, "Rodada {$round}", 30, 180, $white);

        $date = $game->date?->format('d/m/Y');
        if ($date) {
            $this->drawCenteredText($canvas, $date, 22, self::HEIGHT - 80, $muted);
        }

        return $this->saveJpeg($canvas, $destAbsolute);
    }

    private function renderFallback(Game $game, string $destAbsolute): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);

        $bg = imagecolorallocate($canvas, 10, 10, 14);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $accent = imagecolorallocate($canvas, 234, 179, 8);
        $muted = imagecolorallocate($canvas, 170, 170, 180);

        $round = $game->round ?? $game->id;
        $this->drawCenteredText($canvas, 'DRAFT FINALIZADO', 52, 820, $accent, true);
        $this->drawCenteredText($canvas, "Rodada {$round}", 34, 900, $white);

        $date = $game->date?->format('d/m/Y');
        if ($date) {
            $this->drawCenteredText($canvas, $date, 24, 970, $muted);
        }

        $this->drawCenteredText($canvas, 'QNF Porto', 24, 1100, $muted);

        return $this->saveJpeg($canvas, $destAbsolute);
    }

    private function resolveExistingLineupsOrCaptains(Game $game): ?string
    {
        $round = $game->round ?? $game->id;
        $lineups = storage_path("app/public/lineups/{$round}/lineups.png");
        if (is_file($lineups)) {
            return $lineups;
        }

        if ($game->captains_image) {
            return PublicStorage::localPath($game->captains_image);
        }

        return null;
    }

    private function drawCenteredText(
        \GdImage $canvas,
        string $text,
        int $size,
        int $y,
        int $color,
        bool $boldApprox = false,
    ): void {
        $font = $this->fontPath();

        if ($font) {
            $bbox = imagettfbbox($size, 0, $font, $text);
            $textWidth = (int) abs($bbox[2] - $bbox[0]);
            $x = (int) ((self::WIDTH - $textWidth) / 2);
            imagettftext($canvas, $size, 0, $x, $y, $color, $font, $text);
            if ($boldApprox) {
                imagettftext($canvas, $size, 0, $x + 1, $y, $color, $font, $text);
            }

            return;
        }

        $approxWidth = strlen($text) * imagefontwidth(5);
        $x = (int) ((self::WIDTH - $approxWidth) / 2);
        imagestring($canvas, 5, max(10, $x), max(10, $y - 14), $text, $color);
    }

    private function fontPath(): ?string
    {
        foreach ([
            public_path('fonts/Anton-Regular.ttf'),
            public_path('fonts/anton.ttf'),
            resource_path('fonts/Anton-Regular.ttf'),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function saveJpeg(\GdImage $canvas, string $destAbsolute): string
    {
        File::ensureDirectoryExists(dirname($destAbsolute));

        if (! @imagejpeg($canvas, $destAbsolute, 90)) {
            imagedestroy($canvas);
            throw new InstagramAssetException('Falha ao gravar JPEG do story de draft.');
        }

        imagedestroy($canvas);

        return $destAbsolute;
    }
}
