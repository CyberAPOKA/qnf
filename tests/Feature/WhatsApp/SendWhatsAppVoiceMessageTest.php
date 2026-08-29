<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SendWhatsAppVoiceMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'fish-audio.enabled' => true,
            'fish-audio.api_key' => 'test-fish-key',
            'fish-audio.model' => 's2.1-pro-free',
            'fish-audio.base_url' => 'https://api.fish.audio',
            'fish-audio.voices.lula' => 'voice-lula-id',
            'fish-audio.voices.bolsonaro' => 'voice-bolsonaro-id',
            'fish-audio.disk' => 'local',
            'fish-audio.http.retries' => 1,
            'fish-audio.http.retry_sleep_ms' => 0,
            'whatsapp.active' => true,
            'whatsapp.group_id' => '120363407629757550@g.us',
        ]);
    }

    public function test_admin_sends_a_voice_message_to_the_group(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendAudioToGroup')
                ->once()
                ->withArgs(function (string $path, string $caption = '') {
                    return is_file($path)
                        && str_ends_with($path, '.mp3')
                        && file_get_contents($path) === 'ID3fake-mp3-bytes'
                        && $caption === '';
                })
                ->andReturn(true);
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson(route('api.whatsapp.send-voice'), [
                'message' => 'Boa noite, QNF.',
                'voice' => 'bolsonaro',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.fish.audio/v1/tts'
            && $request['text'] === 'Boa noite, QNF.'
            && $request['reference_id'] === 'voice-bolsonaro-id');

        $this->assertSame([], Storage::disk('local')->allFiles('whatsapp'));
    }

    public function test_player_cannot_send_a_voice_message(): void
    {
        Http::fake();

        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)
            ->postJson(route('api.whatsapp.send-voice'), [
                'message' => 'Boa noite, QNF.',
                'voice' => 'lula',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_message_cannot_exceed_500_characters(): void
    {
        Http::fake();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson(route('api.whatsapp.send-voice'), [
                'message' => str_repeat('a', 501),
                'voice' => 'lula',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        Http::assertNothingSent();
    }

    public function test_voice_must_be_lula_or_bolsonaro(): void
    {
        Http::fake();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson(route('api.whatsapp.send-voice'), [
                'message' => 'Boa noite, QNF.',
                'voice' => 'cuca',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice');

        Http::assertNothingSent();
    }

    public function test_whatsapp_failure_returns_an_error(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendAudioToGroup')->once()->andReturn(false);
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson(route('api.whatsapp.send-voice'), [
                'message' => 'Boa noite, QNF.',
                'voice' => 'lula',
            ])
            ->assertStatus(502)
            ->assertJson([
                'success' => false,
                'error' => 'WhatsApp voice message send failed.',
            ]);
    }
}