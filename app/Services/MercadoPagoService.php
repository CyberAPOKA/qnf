<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @return array{id: int, qr_code: string, qr_code_base64: string}
     */
    public function createPixPayment(int $amountCents, string $description, string $externalReference, string $payerEmail = ''): array
    {
        $amount = $amountCents / 100;
        $email = (str_ends_with($payerEmail, '@player.local') || blank($payerEmail))
            ? "pagamento+{$externalReference}@academiaportodefutsal.com"
            : $payerEmail;

        $idempotencyKey = $externalReference . '-' . now()->timestamp . '-' . mt_rand(1000, 9999);

        $expiration = now()->addDays(5)->format('Y-m-d\TH:i:sP');

        $response = Http::withToken($this->accessToken)
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post("{$this->baseUrl}/v1/payments", [
                'transaction_amount' => $amount,
                'description' => $description,
                'payment_method_id' => 'pix',
                'date_of_expiration' => $expiration,
                'payer' => [
                    'email' => $email,
                ],
                'external_reference' => $externalReference,
                'notification_url' => config('services.mercadopago.webhook_url'),
            ]);

        if (! $response->successful()) {
            Log::error('Mercado Pago payment creation failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'external_reference' => $externalReference,
            ]);

            throw new \RuntimeException('Falha ao criar pagamento no Mercado Pago: '.$response->body());
        }

        $data = $response->json();
        $transactionData = $data['point_of_interaction']['transaction_data'] ?? [];

        return [
            'id' => $data['id'],
            'qr_code' => $transactionData['qr_code'] ?? '',
            'qr_code_base64' => $transactionData['qr_code_base64'] ?? '',
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
}
