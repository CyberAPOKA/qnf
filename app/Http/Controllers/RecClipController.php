<?php

namespace App\Http\Controllers;

use App\Models\RecClip;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecClipController extends Controller
{
    public function download(RecClip $clip): BinaryFileResponse
    {
        $path = PublicStorage::localPath($clip->file_path)
            ?? (Storage::disk('public')->exists($clip->file_path)
                ? Storage::disk('public')->path($clip->file_path)
                : null);

        if (! $path || ! is_file($path)) {
            abort(404, 'Clip não encontrado.');
        }

        $filename = sprintf(
            'rec-%s-%s.webm',
            $clip->camera_tag ?: 'cam',
            $clip->created_at?->format('His') ?: $clip->id,
        );

        return response()->download($path, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
