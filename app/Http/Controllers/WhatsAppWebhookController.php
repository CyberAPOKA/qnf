<?php

namespace App\Http\Controllers;

use App\Support\PhoneNumber;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\WhatsAppCommandProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function handle(Request $request, WhatsAppCommandProcessor $processor): JsonResponse
    {
        $message = IncomingWhatsAppMessage::fromRequest($request);

        $result = $processor->process($message);

        Log::info('[WhatsApp] command', [
            'chat_id' => $message->chatId,
            'body' => mb_substr($message->body, 0, 80),
            'status' => $result['status'],
            'has_reply' => is_string($result['reply']) && $result['reply'] !== '',
            'phone_last8' => PhoneNumber::lastEight($message->authorPhone ?? $message->authorId),
        ]);

        return response()->json($result);
    }
}
