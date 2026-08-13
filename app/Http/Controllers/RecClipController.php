<?php

namespace App\Http\Controllers;

use App\Models\RecClip;
use App\Services\RecClipNormalizeService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecClipController extends Controller
{
    public function download(RecClip $clip, RecClipNormalizeService $normalize): BinaryFileResponse
    {
        $mp4Path = $normalize->ensureMp4($clip);

        if (! $mp4Path || ! is_file($mp4Path)) {
            abort(500, 'Não foi possível converter o vídeo para MP4.');
        }

        $filename = sprintf(
            'rec-%s-%s.mp4',
            $clip->camera_tag ?: 'cam',
            $clip->created_at?->format('His') ?: $clip->id,
        );

        return response()->download($mp4Path, $filename, [
            'Content-Type' => 'video/mp4',
        ]);
    }
}
