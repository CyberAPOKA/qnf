<?php

namespace Tests\Unit\FishAudio;

use App\Exceptions\FishAudio\FishAudioException;
use App\Services\FishAudio\FishAudioService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FishAudioServiceTest extends TestCase
{
    private FishAudioService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fish-audio.enabled' => true,
            'fish-audio.api_key' => 'test-fish-key',
            'fish-audio.model' => 's2.1-pro-free',
            'fish-audio.base_url' => 'https://api.fish.audio',
            'fish-audio.voices.lula' => 'voice-lula-id',
            'fish-audio.voices.bolsonaro' => 'voice-bolsonaro-id',
            'fish-audio.voices.neymar' => 'voice-neymar-id',
            'fish-audio.http.connect_timeout' => 10,
            'fish-audio.http.timeout' => 60,
            'fish-audio.http.retries' => 3,
            'fish-audio.http.retry_sleep_ms' => 0,
        ]);

        $this->service = app(FishAudioService::class);
    }

    public function test_it_generates_mp3_and_sends_expected_headers(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $audio = $this->service->generate('Convocação do time amarelo.', 'lula');

        $this->assertSame('ID3fake-mp3-bytes', $audio->contents);
        $this->assertSame('mp3', $audio->format);
        $this->assertSame(strlen('ID3fake-mp3-bytes'), $audio->size());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fish.audio/v1/tts'
                && $request->hasHeader('Authorization', 'Bearer test-fish-key')
                && $request->hasHeader('model', 's2.1-pro-free')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request['text'] === 'Convocação do time amarelo.'
                && $request['reference_id'] === 'voice-lula-id'
                && $request['format'] === 'mp3';
        });
    }

    public function test_it_sends_the_configured_reference_id_for_bolsonaro(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->service->generate('Texto.', 'bolsonaro');

        Http::assertSent(fn ($request) => $request['reference_id'] === 'voice-bolsonaro-id');
    }

    public function test_it_sends_the_configured_reference_id_for_neymar(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->service->generate('Texto.', 'neymar');

        Http::assertSent(fn ($request) => $request['reference_id'] === 'voice-neymar-id');
    }

    public function test_it_rejects_an_unknown_voice_without_calling_the_api(): void
    {
        Http::fake();

        try {
            $this->service->generate('Texto.', 'someone-else');
            $this->fail('Expected FishAudioException');
        } catch (FishAudioException $e) {
            $this->assertStringContainsString('Invalid narrator voice', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_handles_401_without_retrying(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            $this->service->generate('Texto.', 'lula');
            $this->fail('Expected FishAudioException');
        } catch (FishAudioException $e) {
            $this->assertSame(401, $e->status);
            $this->assertFalse($e->transient);
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_429_then_fails(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response(['message' => 'Too Many Requests'], 429),
        ]);

        try {
            $this->service->generate('Texto.', 'lula');
            $this->fail('Expected FishAudioException');
        } catch (FishAudioException $e) {
            $this->assertSame(429, $e->status);
            $this->assertTrue($e->transient);
        }

        Http::assertSentCount(3);
    }

    public function test_it_retries_500_then_fails(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response(['message' => 'Server error'], 500),
        ]);

        try {
            $this->service->generate('Texto.', 'lula');
            $this->fail('Expected FishAudioException');
        } catch (FishAudioException $e) {
            $this->assertSame(500, $e->status);
            $this->assertTrue($e->transient);
        }

        Http::assertSentCount(3);
    }

    public function test_it_handles_timeouts(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            $this->service->generate('Texto.', 'lula');
            $this->fail('Expected FishAudioException');
        } catch (FishAudioException $e) {
            $this->assertTrue($e->transient);
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function test_it_does_not_include_the_api_key_in_exception_messages(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('Unauthorized test-fish-key', 401),
        ]);

        try {
            $this->service->generate('Texto.', 'lula');
            $this->fail('Expected FishAudioException');
        } catch (FishAudioException $e) {
            $this->assertStringNotContainsString('test-fish-key', $e->getMessage());
        }
    }
}
