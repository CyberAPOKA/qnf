<?php

namespace App\Services;

use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WeekTeamImageService
{
    private const WIDTH = 1080;
    private const HEIGHT = 1920;

    private const LABEL_MARGIN_TOP = -30;

    private const POSITIONS = [
        'captain'      => ['x' => 0,   'y' => 350, 'w' => 550, 'h' => 1650],

        'goalkeeper'   => ['x' => 660, 'y' => 180, 'w' => 250, 'h' => 400,  'label_mt' => -50],
        'fixed'        => ['x' => 660, 'y' => 500, 'w' => 250, 'h' => 400,  'label_mt' => -50],
        'winger_left'  => ['x' => 440, 'y' => 630, 'w' => 250, 'h' => 400,  'label_mt' => -50],
        'winger_right' => ['x' => 860, 'y' => 630, 'w' => 250, 'h' => 400,  'label_mt' => -50],
        'pivot'        => ['x' => 660, 'y' => 930, 'w' => 250, 'h' => 400,  'label_mt' => -50],
    ];

    /**
     * @return string[] List of generated image paths (relative to public storage)
     */
    public function generate(Game $game): array
    {
        $outputDir = storage_path('app/public/week_team');

        File::ensureDirectoryExists($outputDir);

        // Delete any previous images for this game
        foreach (File::files($outputDir) as $file) {
            if (str_contains($file->getFilename(), '-'.$game->id.'.')) {
                File::delete($file->getPathname());
            }
        }

        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);
        $winnerColors = $this->getWinnerColors($game);

        if (empty($winnerColors)) {
            return [];
        }

        $paths = [];

        foreach ($winnerColors as $color) {
            $players = $this->resolvePlayersForColor($game, $color);

            if (empty($players)) {
                continue;
            }

            $path = $this->generateImage($game, $color, $players, $outputDir);
            $paths[] = $path;
        }

        return $paths;
    }

    protected function generateImage(Game $game, TeamColor $color, array $players, string $outputDir): string
    {
        $basePath = public_path('assets/week_team/base_template.png');

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

        foreach ($players as $slot => $player) {
            if (! isset(self::POSITIONS[$slot]) || ! $player) {
                continue;
            }

            $pos = self::POSITIONS[$slot];
            $photoPath = $this->resolvePhotoPath($player, $slot === 'captain');

            $placed = $this->placePlayer(
                $canvas,
                $photoPath,
                $pos['x'],
                $pos['y'],
                $pos['w'],
                $pos['h'],
                fill: $slot === 'captain'
            );

            if (! $placed) {
                continue;
            }

            $labelMt = $pos['label_mt'] ?? self::LABEL_MARGIN_TOP;
            $this->drawNameCard(
                $canvas,
                $this->firstName($player->name),
                $placed['x'],
                $placed['y'] + $placed['h'] + $labelMt,
                $placed['w']
            );
        }

        $fileName = 'week-team-game-'.$color->value.'-'.$game->id.'.png';
        $fullPath = $outputDir.DIRECTORY_SEPARATOR.$fileName;

        imagepng($canvas, $fullPath);
        imagedestroy($canvas);

        return 'week_team/'.$fileName;
    }

    protected function resolvePlayersForColor(Game $game, TeamColor $color): array
    {
        $team = $game->teams->firstWhere('color', $color);

        if (! $team || ! $team->captain) {
            return [];
        }

        $captain = $team->captain;

        $draftedPlayers = $game->draftPicks
            ->where('team_color', $color)
            ->sortBy('id')
            ->pluck('pickedUser')
            ->filter()
            ->unique('id')
            ->values();

        $goalkeeper = $draftedPlayers->first(fn (User $u) => $u->position === Position::GOALKEEPER);

        $linePlayers = $draftedPlayers
            ->filter(fn (User $u) => $u->position !== Position::GOALKEEPER)
            ->values();

        // Captain appears in both: the large highlight and one of the court positions
        $allLinePlayers = collect([$captain])
            ->merge($linePlayers)
            ->unique('id')
            ->values();

        return [
            'captain'      => $captain,
            'goalkeeper'   => $goalkeeper,
            'fixed'        => $allLinePlayers->get(0),
            'winger_left'  => $allLinePlayers->get(1),
            'winger_right' => $allLinePlayers->get(2),
            'pivot'        => $allLinePlayers->get(3),
        ];
    }

    /**
     * @return TeamColor[] Winner colors (empty if all teams tied)
     */
    protected function getWinnerColors(Game $game): array
    {
        $teams = $game->teams->filter(fn ($team) => $team->score !== null);

        if ($teams->isEmpty()) {
            return [];
        }

        $maxScore = (int) $teams->max('score');
        $winners = $teams->filter(fn ($t) => (int) $t->score === $maxScore);

        Log::info('WeekTeam scores', [
            'scores' => $teams->mapWithKeys(fn ($t) => [$t->color->value => $t->score])->all(),
            'max' => $maxScore,
            'winners' => $winners->pluck('color')->map(fn ($c) => $c->value)->all(),
        ]);

        // All teams tied — no winner
        if ($winners->count() === $teams->count()) {
            return [];
        }

        return $winners->pluck('color')->all();
    }

    protected function resolvePhotoPath(User $player, bool $useSidePhoto): string
    {
        $column = $useSidePhoto ? 'photo_side' : 'photo_front';
        $fallback = public_path('assets/week_team/unknown_player.png');

        if (! $player->$column) {
            return $fallback;
        }

        $path = storage_path('app/public/' . ltrim($player->$column, '/'));

        return file_exists($path) ? $path : $fallback;
    }

    protected function placePlayer(\GdImage $canvas, string $photoPath, int $x, int $y, int $targetW, int $targetH, bool $fill = false): ?array
    {
        $info = @getimagesize($photoPath);

        if (! $info) {
            return null;
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($photoPath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($photoPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($photoPath),
            default => null,
        };

        if (! $src) {
            return null;
        }

        imagealphablending($src, true);
        imagesavealpha($src, true);

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($fill) {
            // Fill mode: scale to cover the target area, crop excess
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

            // Crop to target size (center horizontally, top-aligned)
            $cropX = (int) (($newW - $targetW) / 2);
            $cropped = imagecreatetruecolor($targetW, $targetH);
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $transparent2 = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
            imagefill($cropped, 0, 0, $transparent2);
            imagecopy($cropped, $resized, 0, 0, $cropX, 0, $targetW, $targetH);
            imagedestroy($resized);

            imagealphablending($canvas, true);
            imagecopy($canvas, $cropped, $x, $y, 0, 0, $targetW, $targetH);
            imagedestroy($cropped);

            return ['x' => $x, 'y' => $y, 'w' => $targetW, 'h' => $targetH];
        }

        // Fit mode: scale to fit inside target, preserve aspect ratio
        $scale = min($targetW / $srcW, $targetH / $srcH);
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);

        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($src);

        $finalX = $x + (int) (($targetW - $newW) / 2);
        $finalY = $y + ($targetH - $newH);

        imagealphablending($canvas, true);
        imagecopy($canvas, $resized, $finalX, $finalY, 0, 0, $newW, $newH);
        imagedestroy($resized);

        return [
            'x' => $finalX,
            'y' => $finalY,
            'w' => $newW,
            'h' => $newH,
        ];
    }

    protected function drawNameCard(\GdImage $canvas, string $name, int $x, int $y, int $playerWidth): void
    {
        $fontPath = public_path('fonts/Anton-Regular.ttf');

        if (! file_exists($fontPath) || blank($name)) {
            return;
        }

        $displayName = mb_strtoupper($name);
        $fontSize = 26;
        $paddingX = 12;
        $paddingY = 12;
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

        $this->drawRoundedRect(
            $canvas,
            $cardX,
            $cardY,
            $cardX + $cardW,
            $cardY + $cardH,
            $radius,
            $yellow
        );

        $textX = $cardX + $paddingX;
        $textY = $cardY + $paddingY + $textHeight;

        imagettftext($canvas, $fontSize, 0, $textX, $textY, $black, $fontPath, $displayName);
    }

    protected function drawRoundedRect(\GdImage $canvas, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($canvas, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($canvas, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($canvas, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    protected function firstName(?string $name): string
    {
        if (! $name) {
            return '';
        }

        return Str::of(trim($name))->explode(' ')->filter()->first() ?? '';
    }
}