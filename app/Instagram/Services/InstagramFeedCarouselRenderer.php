<?php

namespace App\Instagram\Services;

use App\Enums\TeamColor;
use App\Instagram\Exceptions\InstagramAssetException;
use App\Models\Game;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstagramFeedCarouselRenderer
{
    private const WIDTH = 1080;

    private const HEIGHT = 1350;

    private const COLOR_HEX = [
        'green' => '#22c55e',
        'yellow' => '#eab308',
        'blue' => '#3b82f6',
    ];

    public function renderCover(Game $game, string $destAbsolute): string
    {
        $canvas = $this->createCanvas();
        $accent = $this->allocateHex($canvas, '#eab308');
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 180, 180, 190);

        $this->drawAccentBar($canvas, $accent);
        $this->drawLogo($canvas);

        $round = $game->round ?? $game->id;
        $date = $game->date?->format('d/m/Y') ?? '';

        $this->drawCenteredText($canvas, 'TIMES DA SEMANA', 72, 420, $white, true);
        $this->drawCenteredText($canvas, "Rodada {$round}", 42, 520, $accent);
        if ($date !== '') {
            $this->drawCenteredText($canvas, $date, 28, 590, $muted);
        }

        $this->drawCenteredText($canvas, 'QNF Porto', 26, 1240, $muted);

        return $this->saveJpeg($canvas, $destAbsolute);
    }

    /**
     * @param  list<array{name: string, instagram_username?: string|null, is_captain: bool}>  $players
     */
    public function renderTeamCard(
        Game $game,
        TeamColor $color,
        int $points,
        array $players,
        string $destAbsolute,
        ?int $goals = null,
    ): string {
        $canvas = $this->createCanvas();
        $hex = self::COLOR_HEX[$color->value] ?? '#eab308';
        $accent = $this->allocateHex($canvas, $hex);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 170, 170, 180);
        $soft = imagecolorallocate($canvas, 40, 40, 48);

        $this->drawAccentBar($canvas, $accent);
        $this->drawLogo($canvas);

        $round = $game->round ?? $game->id;
        $teamScore = $goals;
        if ($teamScore === null) {
            $team = $game->teams->firstWhere('color', $color);
            $teamScore = $team?->score !== null ? (int) $team->score : null;
        }

        $this->drawCenteredText($canvas, 'Time ' . $color->label(), 56, 280, $accent, true);
        $this->drawCenteredText($canvas, "Rodada {$round}", 26, 350, $muted);

        $pointsLabel = $this->pointsLabel($points);
        $this->drawRoundedBadge($canvas, $pointsLabel, 540, 430, $accent, $soft, $white);

        if ($teamScore !== null) {
            $goalsLabel = $teamScore === 1 ? '1 gol' : "{$teamScore} gols";
            $this->drawCenteredText($canvas, $goalsLabel . ' na partida', 24, 520, $muted);
        }

        $y = 600;
        foreach ($players as $player) {
            $name = trim((string) ($player['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $label = $name;
            if (! empty($player['is_captain'])) {
                $label .= ' (C)';
            }

            $ig = $player['instagram_username'] ?? null;
            if (is_string($ig) && $ig !== '') {
                $label .= '  @' . ltrim($ig, '@');
            }

            $this->drawCenteredText($canvas, $this->truncate($label, 42), 28, $y, $white);
            $y += 56;

            if ($y > 1180) {
                break;
            }
        }

        return $this->saveJpeg($canvas, $destAbsolute);
    }

    private function createCanvas(): \GdImage
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);

        $bg = imagecolorallocate($canvas, 12, 12, 16);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        return $canvas;
    }

    private function drawAccentBar(\GdImage $canvas, int $accent): void
    {
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, 18, $accent);
        imagefilledrectangle($canvas, 0, self::HEIGHT - 18, self::WIDTH, self::HEIGHT, $accent);
    }

    private function drawLogo(\GdImage $canvas): void
    {
        $logoPath = $this->resolveLogoPath();
        if (! $logoPath) {
            return;
        }

        $info = @getimagesize($logoPath);
        if (! $info) {
            return;
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($logoPath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($logoPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($logoPath) : null,
            default => null,
        };

        if (! $src) {
            return;
        }

        $targetW = 220;
        $scale = $targetW / imagesx($src);
        $targetH = (int) round(imagesy($src) * $scale);
        $x = (int) ((self::WIDTH - $targetW) / 2);
        $y = 60;

        imagecopyresampled($canvas, $src, $x, $y, 0, 0, $targetW, $targetH, imagesx($src), imagesy($src));
        imagedestroy($src);
    }

    private function resolveLogoPath(): ?string
    {
        $candidates = [
            public_path('assets/images/logo.svg'),
            public_path('assets/images/logo.svg'),
            public_path('assets/images/logo_mini.svg'),
            public_path('assets/week_team/logo.svg'),
            public_path('assets/week_team/logo.svg'),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        foreach ([public_path('assets/images'), public_path('assets/week_team'), public_path('assets')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $matches = glob($dir . DIRECTORY_SEPARATOR . '*logo*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
            if ($matches !== []) {
                return $matches[0];
            }
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

    private function drawRoundedBadge(
        \GdImage $canvas,
        string $text,
        int $centerX,
        int $centerY,
        int $accent,
        int $fill,
        int $textColor,
    ): void {
        $font = $this->fontPath();
        $fontSize = 36;
        $paddingX = 36;
        $paddingY = 22;

        if ($font) {
            $bbox = imagettfbbox($fontSize, 0, $font, $text);
            $textWidth = (int) abs($bbox[2] - $bbox[0]);
            $textHeight = (int) abs($bbox[7] - $bbox[1]);
        } else {
            $textWidth = strlen($text) * imagefontwidth(5);
            $textHeight = imagefontheight(5);
        }

        $w = $textWidth + ($paddingX * 2);
        $h = $textHeight + ($paddingY * 2);
        $x1 = (int) ($centerX - ($w / 2));
        $y1 = (int) ($centerY - ($h / 2));
        $x2 = $x1 + $w;
        $y2 = $y1 + $h;
        $radius = 24;

        imagefilledrectangle($canvas, $x1 + $radius, $y1, $x2 - $radius, $y2, $fill);
        imagefilledrectangle($canvas, $x1, $y1 + $radius, $x2, $y2 - $radius, $fill);
        imagefilledellipse($canvas, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $fill);
        imagefilledellipse($canvas, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $fill);
        imagefilledellipse($canvas, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $fill);
        imagefilledellipse($canvas, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $fill);

        imagerectangle($canvas, $x1, $y1, $x2, $y2, $accent);

        if ($font) {
            $textX = $x1 + $paddingX;
            $textY = $y1 + $paddingY + $textHeight;
            imagettftext($canvas, $fontSize, 0, $textX, $textY, $textColor, $font, $text);
        } else {
            imagestring($canvas, 5, $x1 + $paddingX, $y1 + $paddingY, $text, $textColor);
        }
    }

    private function fontPath(): ?string
    {
        $candidates = [
            public_path('fonts/Anton-Regular.ttf'),
            public_path('fonts/anton.ttf'),
            resource_path('fonts/Anton-Regular.ttf'),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function allocateHex(\GdImage $canvas, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return imagecolorallocate($canvas, $r, $g, $b);
    }

    private function pointsLabel(int $points): string
    {
        return $points === 1 ? '1 ponto' : "{$points} pontos";
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return Str::of($text)->limit($max)->toString();
    }

    private function saveJpeg(\GdImage $canvas, string $destAbsolute): string
    {
        File::ensureDirectoryExists(dirname($destAbsolute));

        if (! @imagejpeg($canvas, $destAbsolute, 90)) {
            imagedestroy($canvas);
            throw new InstagramAssetException('Falha ao gravar JPEG do carrossel Instagram.');
        }

        imagedestroy($canvas);

        return $destAbsolute;
    }
}
