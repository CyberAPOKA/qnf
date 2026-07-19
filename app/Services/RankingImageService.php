<?php

namespace App\Services;

use App\Support\PublicStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RankingImageService
{
    private const WIDTH = 540;

    private const HEIGHT = 960;

    /**
     * Slot rects [x, y, w, h, leader?] for outfield ranks 1..21.
     *
     * @var list<array{x: int, y: int, w: int, h: int, leader?: bool}>
     */
    private const LINE_SLOTS = [
        ['x' => 145, 'y' => 133, 'w' => 228, 'h' => 28, 'leader' => true],
        // 2–11 left
        ['x' => 38, 'y' => 196, 'w' => 201, 'h' => 27],
        ['x' => 39, 'y' => 255, 'w' => 201, 'h' => 26],
        ['x' => 39, 'y' => 312, 'w' => 201, 'h' => 27],
        ['x' => 40, 'y' => 369, 'w' => 200, 'h' => 27],
        ['x' => 40, 'y' => 427, 'w' => 200, 'h' => 25],
        ['x' => 39, 'y' => 485, 'w' => 201, 'h' => 28],
        ['x' => 39, 'y' => 542, 'w' => 201, 'h' => 28],
        ['x' => 40, 'y' => 599, 'w' => 200, 'h' => 28],
        ['x' => 39, 'y' => 657, 'w' => 201, 'h' => 27],
        ['x' => 39, 'y' => 714, 'w' => 201, 'h' => 29],
        // 12–21 right
        ['x' => 311, 'y' => 196, 'w' => 202, 'h' => 29],
        ['x' => 311, 'y' => 253, 'w' => 202, 'h' => 28],
        ['x' => 311, 'y' => 311, 'w' => 202, 'h' => 28],
        ['x' => 311, 'y' => 368, 'w' => 202, 'h' => 28],
        ['x' => 311, 'y' => 426, 'w' => 202, 'h' => 27],
        ['x' => 311, 'y' => 483, 'w' => 202, 'h' => 29],
        ['x' => 311, 'y' => 541, 'w' => 202, 'h' => 28],
        ['x' => 311, 'y' => 599, 'w' => 202, 'h' => 28],
        ['x' => 311, 'y' => 656, 'w' => 202, 'h' => 30],
        ['x' => 311, 'y' => 714, 'w' => 202, 'h' => 27],
    ];

    /**
     * Slot rects for goalkeeper ranks 1..8.
     *
     * @var list<array{x: int, y: int, w: int, h: int, leader?: bool}>
     */
    private const GK_SLOTS = [
        ['x' => 56, 'y' => 815, 'w' => 132, 'h' => 23, 'leader' => true],
        ['x' => 92, 'y' => 856, 'w' => 117, 'h' => 21],
        ['x' => 93, 'y' => 893, 'w' => 116, 'h' => 20],
        ['x' => 235, 'y' => 820, 'w' => 116, 'h' => 20],
        ['x' => 233, 'y' => 856, 'w' => 116, 'h' => 21],
        ['x' => 232, 'y' => 893, 'w' => 116, 'h' => 19],
        ['x' => 381, 'y' => 820, 'w' => 116, 'h' => 20],
        ['x' => 380, 'y' => 857, 'w' => 117, 'h' => 21],
    ];

    public function __construct(
        private readonly ScoringService $scoringService,
    ) {}

    /**
     * @return string|null Relative storage path of the generated image
     */
    public function generate(?int $upToRound = null): ?string
    {
        $stats = $this->scoringService->getPlayerStats(
            userIds: null,
            includeGuests: false,
            upToRound: $upToRound,
        );

        $sorted = $stats->sortBy([
            ['total_points', 'desc'],
            ['games_played', 'desc'],
            ['name', 'asc'],
        ])->values();

        $line = $sorted->where('position', '!=', 'goalkeeper')->take(21)->values();
        $goalkeepers = $sorted->where('position', 'goalkeeper')->take(8)->values();

        if ($line->isEmpty() && $goalkeepers->isEmpty()) {
            return null;
        }

        $outputDir = storage_path('app/public/ranking');
        File::ensureDirectoryExists($outputDir);

        $path = $this->generateImage(
            $line->all(),
            $goalkeepers->all(),
            $outputDir,
            'ranking'
        );

        return $path;
    }

    /**
     * @param  list<object{name: string, photo_front: ?string}>  $line
     * @param  list<object{name: string, photo_front: ?string}>  $goalkeepers
     */
    private function generateImage(array $line, array $goalkeepers, string $outputDir, string $relativePath): string
    {
        $basePath = public_path('assets/images/ranking.png');

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        $base = imagecreatefrompng($basePath);
        imagealphablending($base, true);
        imagesavealpha($base, true);

        imagecopyresampled(
            $canvas,
            $base,
            0,
            0,
            0,
            0,
            self::WIDTH,
            self::HEIGHT,
            imagesx($base),
            imagesy($base)
        );
        imagedestroy($base);

        imagealphablending($canvas, true);

        $lineEntries = [];
        foreach (self::LINE_SLOTS as $i => $slot) {
            if (! isset($line[$i])) {
                break;
            }
            $lineEntries[] = ['player' => $line[$i], 'slot' => $slot];
        }

        $gkEntries = [];
        foreach (self::GK_SLOTS as $i => $slot) {
            if (! isset($goalkeepers[$i])) {
                break;
            }
            $gkEntries[] = ['player' => $goalkeepers[$i], 'slot' => $slot];
        }

        $entries = array_merge($lineEntries, $gkEntries);

        foreach ($entries as $entry) {
            $this->drawPlayerPhoto($canvas, $entry['player'], $entry['slot']);
        }

        foreach ($entries as $entry) {
            $this->drawPlayerName($canvas, $entry['player'], $entry['slot']);
        }

        $fileName = 'ranking.png';
        $fullPath = $outputDir.DIRECTORY_SEPARATOR.$fileName;

        imagepng($canvas, $fullPath);
        imagedestroy($canvas);

        return $relativePath.'/'.$fileName;
    }

    /**
     * @param  object{name: string, photo_front: ?string}  $player
     * @param  array{x: int, y: int, w: int, h: int, leader?: bool}  $slot
     */
    private function drawPlayerPhoto(\GdImage $canvas, object $player, array $slot): void
    {
        $photoSize = max(30, (int) round($slot['h'] * 1.85));
        // Inset into the card (not flush on the left edge)
        $photoX = $slot['x'] + 6;
        // Overflow upward so the head sits above the card edge
        $photoY = $slot['y'] + (int) round($slot['h'] * 0.5) - (int) round($photoSize * 0.78);

        $photoPath = $this->resolvePhotoPath($player->photo_front ?? null);
        $this->placePhoto($canvas, $photoPath, $photoX, $photoY, $photoSize);
    }

    /**
     * @param  object{name: string, photo_front: ?string}  $player
     * @param  array{x: int, y: int, w: int, h: int, leader?: bool}  $slot
     */
    private function drawPlayerName(\GdImage $canvas, object $player, array $slot): void
    {
        $name = $this->firstName($player->name ?? '');
        if ($name === '') {
            return;
        }

        $photoSize = max(30, (int) round($slot['h'] * 1.85));
        $photoX = $slot['x'] + 6;

        $isLeader = ! empty($slot['leader']);
        $textColor = $isLeader
            ? imagecolorallocate($canvas, 10, 30, 90)
            : imagecolorallocate($canvas, 255, 255, 255);

        $fontPath = public_path('fonts/Anton-Regular.ttf');
        $fontSize = $slot['h'] >= 25 ? 11 : 9;
        $displayName = mb_strtoupper($name);

        $textX = $photoX + $photoSize + 8;
        $maxTextWidth = $slot['x'] + $slot['w'] - $textX - 6;

        if (file_exists($fontPath)) {
            while ($fontSize >= 7) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $displayName);
                $textWidth = (int) abs($bbox[2] - $bbox[0]);
                if ($textWidth <= $maxTextWidth) {
                    break;
                }
                $fontSize--;
            }

            $bbox = imagettfbbox($fontSize, 0, $fontPath, $displayName);
            $textHeight = (int) abs($bbox[7] - $bbox[1]);
            $textY = $slot['y'] + (int) (($slot['h'] + $textHeight) / 2) - 1;

            imagettftext($canvas, $fontSize, 0, $textX, $textY, $textColor, $fontPath, $displayName);
        } else {
            $textY = $slot['y'] + (int) (($slot['h'] - 10) / 2);
            imagestring($canvas, 2, $textX, $textY, $displayName, $textColor);
        }
    }

    private function resolvePhotoPath(?string $photoFront): string
    {
        $fallback = public_path('assets/week_team/unknown_player.png');

        if (! $photoFront) {
            return $fallback;
        }

        $path = PublicStorage::localPath($photoFront);

        return $path ?? $fallback;
    }

    private function placePhoto(\GdImage $canvas, string $photoPath, int $x, int $y, int $size): void
    {
        $info = @getimagesize($photoPath);
        if (! $info) {
            return;
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($photoPath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($photoPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($photoPath),
            default => null,
        };

        if (! $src) {
            return;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Face crop: top-center, include full head (start at y=0)
        $faceSize = (int) min($srcW, (int) round($srcH * 0.32));
        $faceSize = max(1, $faceSize);
        $srcX = (int) max(0, ($srcW - $faceSize) / 2);
        $srcY = 0;

        $square = imagecreatetruecolor($size, $size);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
        imagefill($square, 0, 0, $transparent);
        imagecopyresampled($square, $src, 0, 0, $srcX, $srcY, $size, $size, $faceSize, $faceSize);
        imagedestroy($src);

        imagealphablending($canvas, true);
        imagecopy($canvas, $square, $x, $y, 0, 0, $size, $size);
        imagedestroy($square);
    }

    private function firstName(?string $name): string
    {
        if (! $name) {
            return '';
        }

        return Str::of(trim($name))->explode(' ')->filter()->first() ?? '';
    }
}
