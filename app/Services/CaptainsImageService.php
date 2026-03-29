<?php

namespace App\Services;

use App\Enums\Position;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CaptainsImageService
{
    private const WIDTH = 1919;
    private const HEIGHT = 1280;

    private const POSITIONS = [
        'left'   => ['x' => 30,  'y' => 330, 'w' => 600, 'h' => 950, 'photo' => 'side', 'flip' => false],
        'center' => ['x' => 685,  'y' => 330, 'w' => 600, 'h' => 950, 'photo' => 'front', 'flip' => false],
        'right'  => ['x' => 1300, 'y' => 330, 'w' => 600, 'h' => 950, 'photo' => 'side', 'flip' => true],
    ];

    /**
     * @param  User[]|null  $captains  Specific captains to use; if null, picks random ones.
     * @return string|null Relative storage path of the generated image
     */
    public function generate(Game $game, ?array $captains = null): ?string
    {
        $round = $game->round ?? $game->id;
        $outputDir = storage_path("app/public/captains/{$round}");

        if (is_dir($outputDir)) {
            File::cleanDirectory($outputDir);
        }

        File::ensureDirectoryExists($outputDir);

        $captains = $captains ?? $this->pickRandomCaptains();

        if (count($captains) < 3) {
            return null;
        }

        $path = $this->generateImage($captains, $outputDir, "captains/{$round}");

        $game->update(['captains_image' => $path]);

        return $path;
    }

    /**
     * @return User[]
     */
    private function pickRandomCaptains(): array
    {
        return User::where('role', '!=', 'admin')
            ->where('active', true)
            ->where('guest', false)
            ->where('position', '!=', Position::GOALKEEPER)
            ->inRandomOrder()
            ->limit(3)
            ->get()
            ->all();
    }

    private function generateImage(array $captains, string $outputDir, string $relativePath): string
    {
        $basePath = public_path('assets/captains/base_captains.png');

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        $base = imagecreatefrompng($basePath);
        imagealphablending($base, true);
        imagesavealpha($base, true);

        imagecopyresampled(
            $canvas, $base,
            0, 0, 0, 0,
            self::WIDTH, self::HEIGHT,
            imagesx($base), imagesy($base)
        );
        imagedestroy($base);

        $slots = ['left', 'center', 'right'];

        foreach ($slots as $i => $slot) {
            $player = $captains[$i];
            $pos = self::POSITIONS[$slot];

            $photoColumn = $pos['photo'] === 'side' ? 'photo_side' : 'photo_front';
            $photoPath = $this->resolvePhotoPath($player, $photoColumn);

            $this->placePlayer(
                $canvas,
                $photoPath,
                $pos['x'],
                $pos['y'],
                $pos['w'],
                $pos['h'],
                $pos['flip']
            );

            $this->drawNameCard(
                $canvas,
                $this->firstName($player->name),
                $pos['x'],
                $pos['y'] + $pos['h'] + 10,
                $pos['w']
            );
        }

        $fileName = 'captains.png';
        $fullPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

        imagepng($canvas, $fullPath);
        imagedestroy($canvas);

        return $relativePath . '/' . $fileName;
    }

    private function resolvePhotoPath(User $player, string $column): string
    {
        $fallback = public_path('assets/week_team/unknown_player.png');

        if (! $player->$column) {
            return $fallback;
        }

        $path = storage_path('app/public/' . ltrim($player->$column, '/'));

        return file_exists($path) ? $path : $fallback;
    }

    private function placePlayer(\GdImage $canvas, string $photoPath, int $x, int $y, int $targetW, int $targetH, bool $flip): void
    {
        $info = @getimagesize($photoPath);
        if (! $info) {
            return;
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG  => imagecreatefrompng($photoPath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($photoPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($photoPath),
            default        => null,
        };

        if (! $src) {
            return;
        }

        imagealphablending($src, true);
        imagesavealpha($src, true);

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Fill mode: scale to cover, crop excess
        $scale = max($targetW / $srcW, $targetH / $srcH);
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);

        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($src);

        // Crop to target
        $cropX = (int) (($newW - $targetW) / 2);
        $cropped = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        $transparent2 = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
        imagefill($cropped, 0, 0, $transparent2);
        imagecopy($cropped, $resized, 0, 0, $cropX, 0, $targetW, $targetH);
        imagedestroy($resized);

        if ($flip) {
            imageflip($cropped, IMG_FLIP_HORIZONTAL);
        }

        imagealphablending($canvas, true);
        imagecopy($canvas, $cropped, $x, $y, 0, 0, $targetW, $targetH);
        imagedestroy($cropped);
    }

    private function drawNameCard(\GdImage $canvas, string $name, int $x, int $y, int $playerWidth): void
    {
        $fontPath = public_path('fonts/Anton-Regular.ttf');

        if (! file_exists($fontPath) || blank($name)) {
            return;
        }

        $displayName = mb_strtoupper($name);
        $fontSize = 32;
        $paddingX = 14;
        $paddingY = 14;
        $radius = 14;

        $bbox = imagettfbbox($fontSize, 0, $fontPath, $displayName);
        $textWidth = (int) abs($bbox[2] - $bbox[0]);
        $textHeight = (int) abs($bbox[7] - $bbox[1]);

        $cardW = $textWidth + ($paddingX * 2);
        $cardH = $textHeight + ($paddingY * 2);

        $cardX = $x + (int) (($playerWidth - $cardW) / 2);
        $cardY = $y;

        $yellow = imagecolorallocate($canvas, 255, 230, 0);
        $black = imagecolorallocate($canvas, 0, 0, 0);

        $this->drawRoundedRect($canvas, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $radius, $yellow);

        $textX = $cardX + $paddingX;
        $textY = $cardY + $paddingY + $textHeight;

        imagettftext($canvas, $fontSize, 0, $textX, $textY, $black, $fontPath, $displayName);
    }

    private function drawRoundedRect(\GdImage $canvas, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($canvas, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($canvas, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($canvas, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    private function firstName(?string $name): string
    {
        if (! $name) {
            return '';
        }

        return Str::of(trim($name))->explode(' ')->filter()->first() ?? '';
    }
}
