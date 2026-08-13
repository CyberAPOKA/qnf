<?php

namespace App\Http\Controllers;

use App\Models\RecClip;
use App\Services\RecClipNormalizeService;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecClipController extends Controller
{
    public function download(RecClip $clip, RecClipNormalizeService $normalize): BinaryFileResponse
    {
        @set_time_limit(180);

        try {
            $mp4Path = $normalize->ensureMp4($clip);

            if ($mp4Path && is_file($mp4Path)) {
                return response()->download($mp4Path, $this->downloadName($clip, 'mp4'), [
                    'Content-Type' => 'video/mp4',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('REC download mp4 exception', [
                'clip_id' => $clip->id,
                'message' => $e->getMessage(),
            ]);
        }

        $webm = PublicStorage::localPath($clip->file_path)
            ?? (Storage::disk('public')->exists($clip->file_path)
                ? Storage::disk('public')->path($clip->file_path)
                : null);

        if ($webm && is_file($webm)) {
            Log::warning('REC download falling back to webm', ['clip_id' => $clip->id]);

            return response()->download($webm, $this->downloadName($clip, 'webm'), [
                'Content-Type' => 'video/webm',
            ]);
        }

        abort(404, 'Arquivo do clip não encontrado.');
    }

    private function downloadName(RecClip $clip, string $extension): string
    {
        return sprintf(
            'rec-%s-%s.%s',
            $clip->camera_tag ?: 'cam',
            $clip->created_at?->format('His') ?: $clip->id,
            $extension,
        );
    }
}
