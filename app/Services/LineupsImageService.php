<?php

namespace App\Services;

use App\Enums\Position;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LineupsImageService
{
    private const WIDTH = 1920;
    private const HEIGHT = 1280;

    private const PLAYER_W = 240;
    private const PLAYER_H = 360;

    private const PLAYERS_PER_TEAM = 5;

    /**
     * Position of each team group on the canvas.
     */
    private const TEAMS = [
        0 => ['x' => 15,   'y' => 500],
        1 => ['x' => 610,  'y' => 900],
        2 => ['x' => 1170, 'y' => 540],
    ];

    /**
     * Player slot offsets relative to team origin.
     * Index: 0 = goalkeeper (left), 2 = captain (center/front), 3-4 = right of captain (flipped).
     */
    private const PLAYER_SLOTS = [
        0 => ['ox' => 0,   'oy' => 0, 'photo' => 'side',  'flip' => false],
        1 => ['ox' => 130, 'oy' => 0, 'photo' => 'side',  'flip' => false],
        2 => ['ox' => 260, 'oy' => 0, 'photo' => 'front', 'flip' => false],
        3 => ['ox' => 390, 'oy' => 0, 'photo' => 'side',  'flip' => true],
        4 => ['ox' => 520, 'oy' => 0, 'photo' => 'side',  'flip' => true],
    ];

    /**
     * @param  array<int, array<int, int>>  $teamPlayerIds  3 arrays of 5 user IDs each: [goalkeeper, player, captain, player, player]
     */
    public function generate(Game $game, array $teamPlayerIds): ?string
    {
        if (count($teamPlayerIds) !== 3) {
            return null;
        }

        foreach ($teamPlayerIds as $team) {
            if (count($team) !== self::PLAYERS_PER_TEAM) {
                return null;
            }
        }

        $allIds = array_merge(...$teamPlayerIds);
        $users = User::whereIn('id', $allIds)->get()->keyBy('id');

        $teams = [];
        foreach ($teamPlayerIds as $teamIds) {
            $teamUsers = [];
            foreach ($teamIds as $id) {
                $teamUsers[] = $users->get($id);
            }
            $teams[] = $teamUsers;
        }

        $round = $game->round ?? $game->id;
        $outputDir = storage_path("app/public/lineups/{$round}");

        if (is_dir($outputDir)) {
            File::cleanDirectory($outputDir);
        }

        File::ensureDirectoryExists($outputDir);

        $path = $this->generateImage($teams, $outputDir, "lineups/{$round}");

        return $path;
    }

    /**
     * Generate random lineups with 3 teams of 5 players each.
     */
    public function generateRandom(Game $game): ?string
    {
        $goalkeepers = User::where('role', '!=', 'admin')
            ->where('active', true)
            ->where('position', Position::GOALKEEPER)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        if ($goalkeepers->count() < 3) {
            return null;
        }

        $captains = User::where('role', '!=', 'admin')
            ->where('active', true)
            ->where('guest', false)
            ->where('position', '!=', Position::GOALKEEPER)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        if ($captains->count() < 3) {
            return null;
        }

        $captainIds = $captains->pluck('id')->all();
        $goalkeeperIds = $goalkeepers->pluck('id')->all();

        $others = User::where('role', '!=', 'admin')
            ->where('active', true)
            ->where('position', '!=', Position::GOALKEEPER)
            ->whereNotIn('id', $captainIds)
            ->inRandomOrder()
            ->limit(9)
            ->get();

        if ($others->count() < 9) {
            return null;
        }

        $teamPlayerIds = [];
        for ($i = 0; $i < 3; $i++) {
            $teamPlayerIds[] = [
                $goalkeeperIds[$i],
                $others[$i * 3]->id,
                $captainIds[$i],
                $others[$i * 3 + 1]->id,
                $others[$i * 3 + 2]->id,
            ];
        }

        return $this->generate($game, $teamPlayerIds);
    }

    /**
     * @param  array<int, array<int, User|null>>  $teams
     */
    private function generateImage(array $teams, string $outputDir, string $relativePath): string
    {
        $basePath = public_path('assets/images/base_lineups.png');

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

        // Render order: edges first (0,4), then adjacent to captain (1,3), then captain (2)
        $renderOrder = [0, 4, 1, 3, 2];

        // First pass: place all player photos
        foreach ($teams as $teamIndex => $teamPlayers) {
            $team = self::TEAMS[$teamIndex];

            foreach ($renderOrder as $slotIndex) {
                $player = $teamPlayers[$slotIndex] ?? null;
                if (! $player) {
                    continue;
                }

                $slot = self::PLAYER_SLOTS[$slotIndex];
                $x = $team['x'] + $slot['ox'];
                $y = $team['y'] + $slot['oy'];

                $photoColumn = $slot['photo'] === 'front' ? 'photo_front' : 'photo_side';
                $photoPath = $this->resolvePhotoPath($player, $photoColumn);

                $this->placePlayer(
                    $canvas,
                    $photoPath,
                    $x,
                    $y,
                    self::PLAYER_W,
                    self::PLAYER_H,
                    $slot['flip']
                );
            }
        }

        // Second pass: draw all name cards on top
        foreach ($teams as $teamIndex => $teamPlayers) {
            $team = self::TEAMS[$teamIndex];

            foreach ($teamPlayers as $slotIndex => $player) {
                if (! $player) {
                    continue;
                }

                $slot = self::PLAYER_SLOTS[$slotIndex];
                $x = $team['x'] + $slot['ox'];
                $y = $team['y'] + $slot['oy'];

                $this->drawNameCard(
                    $canvas,
                    $this->firstName($player->name),
                    $x,
                    $y,
                    self::PLAYER_W,
                    self::PLAYER_H
                );
            }
        }

        $fileName = 'lineups.png';
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

    private function drawNameCard(\GdImage $canvas, string $name, int $x, int $y, int $playerWidth, int $playerHeight): void
    {
        $fontPath = public_path('fonts/Anton-Regular.ttf');

        if (! file_exists($fontPath) || blank($name)) {
            return;
        }

        $displayName = mb_strtoupper($name);
        $fontSize = 20;
        $paddingX = 8;
        $paddingY = 8;
        $radius = 8;
        $bottomMargin = 0;

        $bbox = imagettfbbox($fontSize, 0, $fontPath, $displayName);
        $textWidth = (int) abs($bbox[2] - $bbox[0]);
        $textHeight = (int) abs($bbox[7] - $bbox[1]);

        $cardW = $textWidth + ($paddingX * 2);
        $cardH = $textHeight + ($paddingY * 2);

        $cardX = $x + (int) (($playerWidth - $cardW) / 2);
        $cardY = $y + $playerHeight - $cardH - $bottomMargin;

        $bgColor = imagecolorallocate($canvas, 0xD2, 0xB0, 0x6A);
        $textColor = imagecolorallocate($canvas, 0, 0, 0);

        $this->drawRoundedRect($canvas, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $radius, $bgColor);

        $textX = $cardX + $paddingX;
        $textY = $cardY + $paddingY + $textHeight;

        imagettftext($canvas, $fontSize, 0, $textX, $textY, $textColor, $fontPath, $displayName);
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
