<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MercadoPagoService
{
    private string $accessToken;

    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
    }

    /**
     * Cria um pagamento Pix no Mercado Pago e retorna os dados do QR code.
     *
     * @param  array{email?: string|null, first_name?: string|null, last_name?: string|null, identification?: array{type: string, number: string}|null}  $payer
     * @return array{id: int, qr_code: string, qr_code_base64: string, status: string|null, status_detail: string|null}
     */
    public function createPixPayment(
        int $amountCents,
        string $description,
        string $externalReference,
        array $payer = [],
        ?string $idempotencyKey = null,
        ?string $deviceSessionId = null,
    ): array {
        $amount = round($amountCents / 100, 2);
        $email = $this->resolvePayerEmail($payer['email'] ?? '', $externalReference);
        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();
        $hasDeviceId = $this->isValidDeviceSessionId($deviceSessionId);

        $response = Http::withToken($this->accessToken)
            ->withHeaders($this->buildHeaders($idempotencyKey, $hasDeviceId ? $deviceSessionId : null))
            ->post("{$this->baseUrl}/v1/payments", $this->buildPaymentPayload(
                $amount,
                $description,
                $externalReference,
                array_merge($payer, ['email' => $email]),
            ));

        if (! $response->successful()) {
            $this->logCreationFailure($response, $externalReference);

            $mpMessage = $response->json('message') ?? $response->json('error') ?? 'erro desconhecido';

            throw new \RuntimeException("Falha ao criar pagamento no Mercado Pago (HTTP {$response->status()}): {$mpMessage}");
        }

        $data = $response->json();
        $transactionData = $data['point_of_interaction']['transaction_data'] ?? [];

        Log::info('Mercado Pago Pix created', [
            'external_reference' => $externalReference,
            'amount' => $amount,
            'payer_email' => $email,
            'has_device_id' => $hasDeviceId,
            'http_status' => $response->status(),
            'mp_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'status_detail' => $data['status_detail'] ?? null,
        ]);

        return [
            'id' => $data['id'],
            'qr_code' => $transactionData['qr_code'] ?? '',
            'qr_code_base64' => $transactionData['qr_code_base64'] ?? '',
            'status' => $data['status'] ?? null,
            'status_detail' => $data['status_detail'] ?? null,
        ];
    }

    /**
     * Cancela um pagamento pendente no Mercado Pago.
     */
    public function cancelPayment(int|string $paymentId): bool
    {
        $response = Http::withToken($this->accessToken)
            ->put("{$this->baseUrl}/v1/payments/{$paymentId}", [
                'status' => 'cancelled',
            ]);

        if (! $response->successful()) {
            Log::warning('Mercado Pago payment cancellation failed', [
                'payment_id' => $paymentId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Consulta o status de um pagamento no Mercado Pago.
     */
    public function getPayment(int|string $paymentId): ?array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/v1/payments/{$paymentId}");

        if (! $response->successful()) {
            Log::warning('Mercado Pago payment query failed', [
                'payment_id' => $paymentId,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * @param  array{email?: string|null, first_name?: string|null, last_name?: string|null, identification?: array{type: string, number: string}|null}  $payer
     * @return array<string, mixed>
     */
    private function buildPaymentPayload(float $amount, string $description, string $externalReference, array $payer): array
    {
        $payload = [
            'transaction_amount' => $amount,
            'description' => $description,
            'payment_method_id' => 'pix',
            'date_of_expiration' => now()->addDays(5)->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s.vP'),
            'payer' => $this->buildPayer($payer),
            'external_reference' => $externalReference,
        ];

        $additionalInfo = $this->buildAdditionalInfo($payer, $description, $externalReference, $amount);

        if ($additionalInfo !== []) {
            $payload['additional_info'] = $additionalInfo;
        }

        $webhookUrl = config('services.mercadopago.webhook_url');

        if (filled($webhookUrl)) {
            $payload['notification_url'] = $webhookUrl;
        }

        return $payload;
    }

    /**
     * @param  array{email?: string|null, first_name?: string|null, last_name?: string|null, identification?: array{type: string, number: string}|null}  $payer
     * @return array<string, mixed>
     */
    private function buildPayer(array $payer): array
    {
        $payload = [];

        if (filled($payer['email'] ?? null)) {
            $payload['email'] = $payer['email'];
        }

        if (filled($payer['first_name'] ?? null)) {
            $payload['first_name'] = $payer['first_name'];
        }

        if (filled($payer['last_name'] ?? null)) {
            $payload['last_name'] = $payer['last_name'];
        }

        $identification = $payer['identification'] ?? null;

        if (is_array($identification) && filled($identification['type'] ?? null) && filled($identification['number'] ?? null)) {
            $payload['identification'] = [
                'type' => $identification['type'],
                'number' => preg_replace('/\D+/', '', (string) $identification['number']),
            ];
        }

        return $payload;
    }

    /**
     * @param  array{email?: string|null, first_name?: string|null, last_name?: string|null}  $payer
     * @return array<string, mixed>
     */
    private function buildAdditionalInfo(array $payer, string $description, string $externalReference, float $amount): array
    {
        $info = [];
        $infoPayer = [];

        if (filled($payer['first_name'] ?? null)) {
            $infoPayer['first_name'] = $payer['first_name'];
        }

        if (filled($payer['last_name'] ?? null)) {
            $infoPayer['last_name'] = $payer['last_name'];
        }

        if ($infoPayer !== []) {
            $info['payer'] = $infoPayer;
        }

        $info['items'] = [[
            'id' => $externalReference,
            'title' => $description,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $amount,
        ]];

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $idempotencyKey, ?string $deviceSessionId): array
    {
        $headers = [
            'X-Idempotency-Key' => $idempotencyKey,
        ];

        if ($this->isValidDeviceSessionId($deviceSessionId)) {
            $headers['X-meli-session-id'] = $deviceSessionId;
        }

        return $headers;
    }

    private function resolvePayerEmail(?string $payerEmail, string $externalReference): string
    {
        if (blank($payerEmail) || str_ends_with((string) $payerEmail, '@player.local')) {
            return "pagamento+{$externalReference}@academiaportodefutsal.com";
        }

        return $payerEmail;
    }

    private function isValidDeviceSessionId(?string $deviceSessionId): bool
    {
        $length = strlen($deviceSessionId ?? '');

        return $length >= 8
            && $length <= 2048
            && preg_match('/^[A-Za-z0-9+\/_.=:-]+$/', $deviceSessionId) === 1;
    }

    private function logCreationFailure(Response $response, string $externalReference): void
    {
        Log::error('Mercado Pago payment creation failed', [
            'status' => $response->status(),
            'body' => $response->json(),
            'external_reference' => $externalReference,
        ]);
    }
}
