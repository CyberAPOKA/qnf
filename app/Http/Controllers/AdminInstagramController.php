<?php

namespace App\Http\Controllers;

use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Services\InstagramPublishingService;
use App\Models\InstagramPublication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminInstagramController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->role === 'admin', 403);

        $publications = InstagramPublication::query()
            ->withCount('items')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (InstagramPublication $publication) => [
                'uuid' => $publication->uuid,
                'type' => $publication->publication_type?->value,
                'type_label' => $publication->publication_type?->label(),
                'trigger' => $publication->trigger_type?->value,
                'trigger_label' => $publication->trigger_type?->label(),
                'status' => $publication->status?->value,
                'attempts' => $publication->attempts,
                'error' => $publication->last_error_message,
                'permalink' => $publication->permalink,
                'items_count' => $publication->items_count,
                'can_retry' => $publication->status === InstagramPublicationStatus::Failed,
                'created_at' => $publication->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            ]);

        return Inertia::render('AdminInstagram', [
            'publications' => $publications,
        ]);
    }

    public function retry(Request $request, InstagramPublication $publication, InstagramPublishingService $publishingService): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $publishingService->retry($publication);

        return back()->with('success', 'Publicação reenfileirada.');
    }
}
