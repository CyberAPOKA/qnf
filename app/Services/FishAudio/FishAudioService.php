<?php

namespace App\Services\FishAudio;

use App\Enums\NarratorVoice;
use App\Exceptions\FishAudio\FishAudioConfigurationException;
use App\Exceptions\FishAudio\FishAudioException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FishAudioService
{
    /**
     * @var list<int>
     */
    private const TRANSIENT_STATUSES = [429, 500, 502, 503, 504];

    public function generate(string $text, string $voice): GeneratedAudio
    {
        $this->validateConfiguration();

        $referenceId = $this->resolveVoiceReference($voice);
        $model = (string) config('fish-audio.model', 's2.1-pro-free');

        Log::info('Fish Audio TTS request', [
            'voice' => $voice,
            'text_length' => mb_strlen($text),
            'model' => $model,
        ]);

        try {
            $response = $this->pendingRequest()->post($this->endpoint(), [
                'text' => $text,
                'reference_id' => $referenceId,
                'format' => 'mp3',
            ]);
        } catch (ConnectionException $e) {
            Log::error('Fish Audio connection failed', [
                'voice' => $voice,
                'text_length' => mb_strlen($text),
                'error' => FishAudioException::sanitize($e->getMessage()),
            ]);

            throw new FishAudioException(
                message: 'Fish Audio request timed out or failed to connect.',
                status: 0,
                transient: true,
                previous: $e,
            );
        } catch (RequestException $e) {
            $status = $e->response?->status() ?? 0;

            Log::error('Fish Audio HTTP error', [
                'voice' => $voice,
                'text_length' => mb_strlen($text),
                'status' => $status,
                'error' => FishAudioException::sanitize($e->getMessage()),
            ]);

            throw new FishAudioException(
                message: 'Fish Audio request failed.',
                status: $status,
                transient: in_array($status, self::TRANSIENT_STATUSES, true),
                previous: $e,
            );
        }

        return $this->audioFromResponse($response, $voice, $text);
    }

    public function resolveVoiceReference(string $voice): string
    {
        $enum = NarratorVoice::tryFrom($voice);

        if (! $enum) {
            throw new FishAudioConfigurationException('Invalid narrator voice.');
        }

        $referenceId = config('fish-audio.voices.'.$enum->value);

        if (! is_string($referenceId) || $referenceId === '') {
            throw new FishAudioConfigurationException("Fish Audio voice [{$enum->value}] is not configured.");
        }

        return $referenceId;
    }

    public function validateConfiguration(): void
    {
        if (! config('fish-audio.enabled')) {
            throw new FishAudioConfigurationException('Fish Audio integration is disabled.');
        }

        $apiKey = config('fish-audio.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new FishAudioConfigurationException('Fish Audio API key is not configured.');
        }
    }

    private function audioFromResponse(Response $response, string $voice, string $text): GeneratedAudio
    {
        if ($response->failed()) {
            Log::error('Fish Audio HTTP error', [
                'voice' => $voice,
                'text_length' => mb_strlen($text),
                'status' => $response->status(),
            ]);

            throw new FishAudioException(
                message: 'Fish Audio request failed.',
                status: $response->status(),
                transient: in_array($response->status(), self::TRANSIENT_STATUSES, true),
            );
        }

        $contents = $response->body();

        if ($contents === '') {
            Log::error('Fish Audio returned empty audio', [
                'voice' => $voice,
                'text_length' => mb_strlen($text),
                'status' => $response->status(),
            ]);

            throw new FishAudioException('Fish Audio returned empty audio.', $response->status());
        }

        $trimmed = ltrim($contents);

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            Log::error('Fish Audio returned JSON instead of audio', [
                'voice' => $voice,
                'text_length' => mb_strlen($text),
                'status' => $response->status(),
            ]);

            throw new FishAudioException('Fish Audio returned a JSON payload instead of audio.', $response->status());
        }

        $audio = new GeneratedAudio($contents, 'mp3');

        Log::info('Fish Audio TTS generated', [
            'voice' => $voice,
            'text_length' => mb_strlen($text),
            'status' => $response->status(),
            'audio_size' => $audio->size(),
        ]);

        return $audio;
    }

    private function pendingRequest(): PendingRequest
    {
        $tries = max(1, (int) config('fish-audio.http.retries', 3));
        $sleep = max(0, (int) config('fish-audio.http.retry_sleep_ms', 1000));

        return Http::withToken((string) config('fish-audio.api_key'))
            ->withHeaders([
                'model' => (string) config('fish-audio.model', 's2.1-pro-free'),
                'Accept' => 'audio/mpeg, application/octet-stream',
            ])
            ->asJson()
            ->connectTimeout((int) config('fish-audio.http.connect_timeout', 10))
            ->timeout((int) config('fish-audio.http.timeout', 60))
            ->retry(
                $tries,
                $sleep,
                function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        return in_array($exception->response?->status(), self::TRANSIENT_STATUSES, true);
                    }

                    return false;
                },
            );
    }

    private function endpoint(): string
    {
        return rtrim((string) config('fish-audio.base_url', 'https://api.fish.audio'), '/').'/v1/tts';
    }
}
