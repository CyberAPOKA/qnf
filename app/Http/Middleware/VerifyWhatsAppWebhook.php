<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWhatsAppWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.whatsapp.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            abort(401);
        }

        $token = $request->bearerToken() ?: $request->header('X-WhatsApp-Token');

        if (! is_string($token) || ! hash_equals($secret, $token)) {
            abort(401);
        }

        return $next($request);
    }
}
