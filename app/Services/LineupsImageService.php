<?php

namespace App\Services;

use App\Enums\Position;
use App\Models\Game;
use App\Models\User;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LineupsImageService
{
    private const WIDTH = 1080;

    private const HEIGHT = 1920;

    private const PLAYERS_PER_TEAM = 5;

    /** Canonical player size — matches the blue row, used for every team. */
    private const PLAYER_W = 170;

    private const PLAYER_H = 203;

    private const PLAYER_OVERFLOW = 1.06;

    /**
     * Horizontal columns on the 1080x1920 canvas (template is scaled from 941x1672).
     *
     * @var array<int, array{x: int, w: int, name_x: int, name_w: int}>
     */
    private const COLUMNS = [
        ['x' => 90, 'w' => 172, 'name_x' => 96, 'name_w' => 158],
        ['x' => 281, 'w' => 168, 'name_x' => 288, 'name_w' => 155],
        ['x' => 471, 'w' => 169, 'name_x' => 476, 'name_w' => 156],
        ['x' => 660, 'w' => 170, 'name_x' => 666, 'name_w' => 157],
        ['x' => 849, 'w' => 169, 'name_x' => 856, 'name_w' => 155],
    ];

    /**
     * Team rows matching the template: green, yellow, blue.
     * photo_h is the same on every row so all players match the blue card size.
     *
     * @var array<int, array{photo_y: int, photo_h: int, name_y: int, name_h: int}>
     */
    private const ROWS = [
        ['photo_y' => 513, 'photo_h' => 203, 'name_y' => 742, 'name_h' => 34],
        ['photo_y' => 997, 'photo_h' => 203, 'name_y' => 1226, 'name_h' => 33],
        ['photo_y' => 1488, 'photo_h' => 203, 'name_y' => 1717, 'name_h' => 38],
    ];

    /**
     * @param  array<int, array<int, int|null>>  $teamPlayerIds  3 arrays of up to 5 user IDs: [goalkeeper, player, captain, player, player]
     */
    public function generate(Game $game, array $teamPlayerIds): ?string
    {
        if (count($teamPlayerIds) !== 3) {
            return null;
        }

        foreach ($teamPlayerIds as $index => $team) {
            $teamPlayerIds[$index] = array_pad(array_values($team), self::PLAYERS_PER_TEAM, null);
        }

        $allIds = array_values(array_filter(array_merge(...$teamPlayerIds)));

        if ($allIds === []) {
            return null;
        }

        $users = User::whereIn('id', $allIds)->get()->keyBy('id');

        $teams = [];
        foreach ($teamPlayerIds as $teamIds) {
            $teamUsers = [];
            foreach ($teamIds as $id) {
                $teamUsers[] = $id ? $users->get($id) : null;
            }
            $teams[] = $teamUsers;
        }

        $round = $game->round ?? $game->id;
        $outputDir = storage_path("app/public/lineups/{$round}");

        if (is_dir($outputDir)) {
            File::cleanDirectory($outputDir);
        }

        File::ensureDirectoryExists($outputDir);

        return $this->generateImage($teams, $outputDir, "lineups/{$round}");
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

        foreach ($teams as $teamIndex => $teamPlayers) {
            foreach ($teamPlayers as $slotIndex => $player) {
                if (! $player || ! isset(self::COLUMNS[$slotIndex], self::ROWS[$teamIndex])) {
                    continue;
                }

                $box = $this->slotBox($teamIndex, $slotIndex);
                $photoPath = $this->resolvePhotoPath($player);

                $this->placePlayer(
                    $canvas,
                    $photoPath,
                    $box['photo_x'],
                    $box['photo_y'],
                    $box['photo_w'],
                    $box['photo_h']
                );
            }
        }

        foreach ($teams as $teamIndex => $teamPlayers) {
            foreach ($teamPlayers as $slotIndex => $player) {
                if (! $player || ! isset(self::COLUMNS[$slotIndex], self::ROWS[$teamIndex])) {
                    continue;
                }

                $box = $this->slotBox($teamIndex, $slotIndex);

                $this->drawPlayerName(
                    $canvas,
                    $this->firstName($player->name),
                    $box['name_x'],
                    $box['name_y'],
                    $box['name_w'],
                    $box['name_h']
                );
            }
        }

        $fileName = 'lineups.png';
        $fullPath = $outputDir.DIRECTORY_SEPARATOR.$fileName;

        imagepng($canvas, $fullPath);
        imagedestroy($canvas);

        return $relativePath.'/'.$fileName;
    }

    /**
     * @return array{photo_x: int, photo_y: int, photo_w: int, photo_h: int, name_x: int, name_y: int, name_w: int, name_h: int}
     */
    private function slotBox(int $row, int $col): array
    {
        $column = self::COLUMNS[$col];
        $rowCfg = self::ROWS[$row];

        return [
            'photo_x' => $column['x'],
            'photo_y' => $rowCfg['photo_y'],
            'photo_w' => $column['w'],
            'photo_h' => $rowCfg['photo_h'],
            'name_x' => $column['name_x'],
            'name_y' => $rowCfg['name_y'],
            'name_w' => $column['name_w'],
            'name_h' => $rowCfg['name_h'],
        ];
    }

    private function resolvePhotoPath(User $player): string
    {
        $fallback = public_path('assets/week_team/unknown_player.png');

        foreach (['photo_front', 'photo_side'] as $column) {
            if (! $player->$column) {
                continue;
            }

            $path = PublicStorage::localPath($player->$column);

            if ($path) {
                return $path;
            }
        }

        return $fallback;
    }

    private function placePlayer(\GdImage $canvas, string $photoPath, int $x, int $y, int $targetW, int $targetH): void
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

        imagealphablending($src, true);
        imagesavealpha($src, true);

        $bounds = $this->opaqueBounds($src);
        $personX = $bounds['x'];
        $personY = $bounds['y'];
        $personW = $bounds['w'];
        $personH = $bounds['h'];

        $imgW = imagesx($src);
        $imgH = imagesy($src);

        // Same body window and scale for every player, matching the blue card.
        $cropH = $this->headToWaistCropHeight($personW, $personH);
        $cropW = max(1, (int) round($cropH * (self::PLAYER_W / self::PLAYER_H)));
        $cropW = min($cropW, $imgW);
        $cropH = min($cropH, $personH, $imgH);

        $cropX = $personX + (int) (($personW - $cropW) / 2);
        $cropX = max(0, min($cropX, $imgW - $cropW));
        $cropY = max(0, $personY);
        if ($cropY + $cropH > $imgH) {
            $cropH = $imgH - $cropY;
        }

        $scale = (self::PLAYER_H / max(1, $cropH)) * self::PLAYER_OVERFLOW;
        $newW = max(1, (int) round($cropW * $scale));
        $newH = max(1, (int) round($cropH * $scale));

        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $src, 0, 0, $cropX, $cropY, $newW, $newH, $cropW, $cropH);
        imagedestroy($src);

        $baseline = $y + $targetH;
        $destX = $x + (int) (($targetW - $newW) / 2);
        $destY = $baseline - $newH;

        $copyX = 0;
        $copyY = 0;
        $copyW = $newW;
        $copyH = $newH;

        if ($destX < 0) {
            $copyX = -$destX;
            $copyW -= $copyX;
            $destX = 0;
        }
        if ($destY < 0) {
            $copyY = -$destY;
            $copyH -= $copyY;
            $destY = 0;
        }
        if ($destX + $copyW > self::WIDTH) {
            $copyW = self::WIDTH - $destX;
        }
        if ($destY + $copyH > $baseline) {
            $copyH = $baseline - $destY;
        }

        if ($copyW > 0 && $copyH > 0) {
            imagealphablending($canvas, true);
            imagecopy($canvas, $resized, $destX, $destY, $copyX, $copyY, $copyW, $copyH);
        }

        imagedestroy($resized);
    }

    /**
     * Height of the head-to-waist window inside a player cutout.
     * Taller (full-body) photos keep only the upper half; bust shots use the full cutout.
     */
    private function headToWaistCropHeight(int $personW, int $personH): int
    {
        $tallness = $personH / max(1, $personW);

        $ratio = match (true) {
            $tallness >= 2.3 => 0.50,
            $tallness >= 1.85 => 0.64,
            $tallness >= 1.45 => 0.82,
            default => 1.0,
        };

        return max(1, (int) round($personH * $ratio));
    }

    /**
     * @return array{x: int, y: int, w: int, h: int}
     */
    private function opaqueBounds(\GdImage $src): array
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $alpha = (imagecolorat($src, $px, $py) >> 24) & 0x7F;
                if ($alpha >= 110) {
                    continue;
                }

                if ($px < $minX) {
                    $minX = $px;
                }
                if ($px > $maxX) {
                    $maxX = $px;
                }
                if ($py < $minY) {
                    $minY = $py;
                }
                if ($py > $maxY) {
                    $maxY = $py;
                }
            }
        }

        if ($maxX < 0) {
            return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h];
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => $maxX - $minX + 1,
            'h' => $maxY - $minY + 1,
        ];
    }

    private function drawPlayerName(\GdImage $canvas, string $name, int $x, int $y, int $width, int $height): void
    {
        $fontPath = $this->fontPath();

        if (! $fontPath || blank($name)) {
            return;
        }

        $displayName = mb_strtoupper($name);
        $maxWidth = max(20, $width - 12);
        $fontSize = 15;

        do {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $displayName);
            $textWidth = (int) abs($bbox[2] - $bbox[0]);
            $textHeight = (int) abs($bbox[7] - $bbox[1]);
            if ($textWidth <= $maxWidth || $fontSize <= 10) {
                break;
            }
            $fontSize--;
        } while (true);

        $textX = $x + (int) (($width - $textWidth) / 2);
        $textY = $y + (int) (($height + $textHeight) / 2) - 1;

        $shadow = imagecolorallocate($canvas, 0, 0, 0);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagettftext($canvas, $fontSize, 0, $textX + 1, $textY + 1, $shadow, $fontPath, $displayName);
        imagettftext($canvas, $fontSize, 0, $textX, $textY, $white, $fontPath, $displayName);
    }

    private function fontPath(): ?string
    {
        foreach ([
            public_path('fonts/Orbitron-ExtraBold.ttf'),
            public_path('fonts/Orbitron-Bold.ttf'),
            public_path('fonts/Anton-Regular.ttf'),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function firstName(?string $name): string
    {
        if (! $name) {
            return '';
        }

        return Str::of(trim($name))->explode(' ')->filter()->first() ?? '';
    }
}
