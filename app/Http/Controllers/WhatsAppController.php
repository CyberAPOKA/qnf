<?php

namespace App\Http\Controllers;

use App\Enums\NarratorVoice;
use App\Exceptions\FishAudio\FishAudioConfigurationException;
use App\Exceptions\FishAudio\FishAudioException;
use App\Services\WhatsAppService;
use App\Services\WhatsAppVoiceMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class WhatsAppController extends Controller
{
    public function sendTest(Request $request, WhatsAppService $whatsApp): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $success = $whatsApp->sendToGroup('BOT QNF: Test A');

        return $success
            ? response()->json(['success' => true])
            : response()->json(['success' => false, 'error' => 'Failed to send'], 502);
    }

    public function sendVoice(Request $request, WhatsAppVoiceMessageService $voiceMessages): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'voice' => ['required', Rule::enum(NarratorVoice::class)],
        ]);

        try {
            $voiceMessages->sendToGroup(
                $validated['message'],
                NarratorVoice::from($validated['voice']),
            );
        } catch (FishAudioConfigurationException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (FishAudioException|RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        return response()->json(['success' => true]);
    }
}
