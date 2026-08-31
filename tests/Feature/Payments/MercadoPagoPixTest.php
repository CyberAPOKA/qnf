<?php

namespace Tests\Feature\Payments;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Payment;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.active' => true,
            'services.mercadopago.access_token' => 'test-access-token',
            'services.mercadopago.webhook_url' => 'https://qnf.test/webhooks/mercadopago',
            'services.pix.amount' => 800,
        ]);
    }

    public function test_it_creates_pix_with_device_id_payer_and_additional_info(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse(), 201),
        ]);

        $player = User::factory()->create(['name' => 'Joao Silva', 'email' => 'joao@example.com']);
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);

        $created = app(PaymentService::class)->createPaymentForPlayer($game, $player, 'device-session-1');

        $this->assertTrue($created);
        $this->assertDatabaseHas('payments', [
            'game_id' => $game->id,
            'user_id' => $player->id,
            'external_id' => '175141270385',
            'pix_payload' => '000201pix',
        ]);

        Http::assertSent(function (Request $request) use ($game, $player) {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->hasHeader('X-meli-session-id', 'device-session-1')
                && filled($request->header('X-Idempotency-Key')[0] ?? null)
                && $data['payer']['email'] === 'joao@example.com'
                && $data['payer']['first_name'] === 'Joao'
                && $data['payer']['last_name'] === 'Silva'
                && ! isset($data['payer']['identification'])
                && $data['additional_info']['payer']['first_name'] === 'Joao'
                && $data['additional_info']['payer']['last_name'] === 'Silva'
                && $data['additional_info']['items'][0]['id'] === "QNF-G{$game->id}-U{$player->id}"
                && $data['additional_info']['items'][0]['description'] === "QNF Futsal - Rodada {$game->round}"
                && $data['additional_info']['items'][0]['unit_price'] == 8
                && ! isset($data['additional_info']['shipments'])
                && $data['notification_url'] === 'https://qnf.test/webhooks/mercadopago';
        });
    }

    public function test_it_creates_pix_without_device_id_and_optional_payer_fields(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse(), 201),
        ]);

        $player = User::factory()->create(['name' => 'Pelé', 'email' => 'guest@player.local']);
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);

        app(PaymentService::class)->createPaymentForPlayer($game, $player);

        Http::assertSent(function (Request $request) use ($game, $player) {
            $data = $request->data();

            return $request->method() === 'POST'
                && ! $request->hasHeader('X-meli-session-id')
                && $data['payer']['email'] === "pagamento+QNF-G{$game->id}-U{$player->id}@academiaportodefutsal.com"
                && $data['payer']['first_name'] === 'Pelé'
                && ! isset($data['payer']['last_name'])
                && ! isset($data['additional_info']['payer']['last_name']);
        });
    }

    public function test_it_sends_identification_only_when_provided(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse(), 201),
        ]);

        app(MercadoPagoService::class)->createPixPayment(
            800,
            'QNF Futsal - Rodada 24',
            'QNF-G60-U29',
            [
                'email' => 'joao@example.com',
                'first_name' => 'Joao',
                'last_name' => 'Silva',
                'identification' => ['type' => 'CPF', 'number' => '529.982.247-25'],
            ],
            'stable-key-1',
        );

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $data['payer']['identification']['type'] === 'CPF'
                && $data['payer']['identification']['number'] === '52998224725'
                && $request->header('X-Idempotency-Key')[0] === 'stable-key-1';
        });
    }

    public function test_it_reuses_idempotency_key_on_retry_after_http_error(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::sequence()
                ->push(['message' => 'temporary'], 500)
                ->push($this->pixResponse(), 201),
        ]);

        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);
        $service = app(PaymentService::class);

        try {
            $service->createPaymentForPlayer($game, $player);
            $this->fail('Expected Mercado Pago exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $payment = Payment::where('game_id', $game->id)->where('user_id', $player->id)->first();
        $this->assertNotNull($payment);
        $this->assertNull($payment->external_id);
        $this->assertNotEmpty($payment->idempotency_key);

        $this->assertTrue($service->createPaymentForPlayer($game, $player));

        $keys = [];
        Http::assertSent(function (Request $request) use (&$keys) {
            if ($request->method() === 'POST') {
                $keys[] = $request->header('X-Idempotency-Key')[0] ?? null;
            }

            return true;
        });

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($payment->idempotency_key, $keys[0]);
    }

    public function test_it_does_not_create_duplicate_charges_for_the_same_player_and_game(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse(), 201),
        ]);

        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);
        $service = app(PaymentService::class);

        $this->assertTrue($service->createPaymentForPlayer($game, $player));
        $this->assertFalse($service->createPaymentForPlayer($game, $player));
        $this->assertSame(1, Payment::where('game_id', $game->id)->where('user_id', $player->id)->count());

        $posts = 0;
        Http::assertSent(function (Request $request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;
            }

            return true;
        });
        $this->assertSame(1, $posts);
    }

    public function test_it_keeps_payment_pending_when_mercado_pago_returns_waiting_transfer(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse([
                'status' => 'pending',
                'status_detail' => 'pending_waiting_transfer',
            ]), 201),
        ]);

        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);

        app(PaymentService::class)->createPaymentForPlayer($game, $player);

        $payment = Payment::where('game_id', $game->id)->where('user_id', $player->id)->first();
        $this->assertNull($payment->paid_at);
        $this->assertSame('175141270385', $payment->external_id);
    }

    public function test_it_confirms_payment_when_create_returns_approved(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse([
                'status' => 'approved',
                'status_detail' => 'accredited',
            ]), 201),
        ]);

        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);

        app(PaymentService::class)->createPaymentForPlayer($game, $player);

        $payment = Payment::where('game_id', $game->id)->where('user_id', $player->id)->first();
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Payment::METHOD_SYSTEM, $payment->method);
    }

    public function test_player_can_ensure_pix_sending_device_id(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response($this->pixResponse(), 201),
        ]);

        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);
        $deviceId = str_repeat('a', 200);

        $this->actingAs($player)
            ->postJson(route('payments.pix.ensure'), [
                'game_id' => $game->id,
                'device_id' => $deviceId,
            ])
            ->assertOk()
            ->assertJsonPath('payment.pix_payload', '000201pix');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-meli-session-id', $deviceId));
    }

    public function test_webhook_pending_does_not_confirm_payment(): void
    {
        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);
        $payment = Payment::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'amount' => 800,
            'pix_payload' => '000201pix',
            'external_id' => '175141270385',
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/175141270385' => Http::response($this->pixResponse([
                'status' => 'pending',
                'status_detail' => 'pending_waiting_transfer',
            ]), 200),
        ]);

        $this->postJson(route('webhooks.mercadopago'), [
            'type' => 'payment',
            'action' => 'payment.updated',
            'data' => ['id' => '175141270385'],
        ])->assertOk()->assertJson(['status' => 'not_approved']);

        $this->assertNull($payment->fresh()->paid_at);
    }

    public function test_webhook_approved_is_idempotent(): void
    {
        $player = User::factory()->create();
        $game = $this->createDraftedGame();
        $this->enroll($game, $player);
        $payment = Payment::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'amount' => 800,
            'pix_payload' => '000201pix',
            'external_id' => '175141270385',
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/175141270385' => Http::response($this->pixResponse([
                'status' => 'approved',
                'status_detail' => 'accredited',
            ]), 200),
        ]);

        $payload = [
            'type' => 'payment',
            'data' => ['id' => '175141270385'],
        ];

        $this->postJson(route('webhooks.mercadopago'), $payload)
            ->assertOk()
            ->assertJson(['status' => 'confirmed']);

        $this->postJson(route('webhooks.mercadopago'), $payload)
            ->assertOk()
            ->assertJson(['status' => 'already_paid']);

        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame(1, Payment::where('id', $payment->id)->whereNotNull('paid_at')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pixResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 175141270385,
            'status' => 'pending',
            'status_detail' => 'pending_waiting_transfer',
            'point_of_interaction' => [
                'transaction_data' => [
                    'qr_code' => '000201pix',
                    'qr_code_base64' => 'base64qr',
                ],
            ],
        ], $overrides);
    }

    private function createDraftedGame(): Game
    {
        return Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'round' => 24,
            'status' => GameStatus::DRAFTED,
        ]);
    }

    private function enroll(Game $game, User $player): void
    {
        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);
    }
}
