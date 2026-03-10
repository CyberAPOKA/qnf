<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
