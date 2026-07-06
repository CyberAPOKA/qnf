<?php

namespace App\Http\Controllers;

use App\Exceptions\YouTube\YouTubeApiException;
use App\Exceptions\YouTube\YouTubeQuotaExceededException;
use App\Exceptions\YouTube\YouTubeVideoNotEmbeddableException;
use App\Exceptions\YouTube\YouTubeVideoNotFoundException;
use App\Exceptions\YouTube\YouTubeVideoTooShortException;
use App\Services\YouTubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouTubeController extends Controller
{
    public function __construct(
        private readonly YouTubeService $youTubeService,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        try {
            $results = $this->youTubeService->search($validated['q']);
        } catch (YouTubeQuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (YouTubeApiException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['results' => $results]);
    }

    public function show(string $videoId): JsonResponse
    {
        try {
            $video = $this->youTubeService->getVideo($videoId);
        } catch (YouTubeVideoNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (YouTubeVideoNotEmbeddableException|YouTubeVideoTooShortException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (YouTubeQuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (YouTubeApiException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['video' => $video]);
    }
}
