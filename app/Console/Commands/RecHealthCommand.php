<?php

namespace App\Console\Commands;

use App\Services\Rec\RecHealthService;
use Illuminate\Console\Command;

class RecHealthCommand extends Command
{
    protected $signature = 'rec:health {--force : Bypass ffmpeg availability cache}';

    protected $description = 'Show REC module health diagnostics';

    public function handle(RecHealthService $health): int
    {
        if ($this->option('force')) {
            $health->ffmpegAvailable(true);
        }

        $snapshot = $health->snapshot();

        foreach ($snapshot as $key => $value) {
            $this->line(sprintf('%s: %s', $key, is_bool($value) ? ($value ? 'yes' : 'no') : $value));
        }

        return $snapshot['ffmpeg'] ? self::SUCCESS : self::FAILURE;
    }
}
