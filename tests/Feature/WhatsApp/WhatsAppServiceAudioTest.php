<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppServiceAudioTest extends TestCase
{
    public function test_send_audio_includes_file_bytes_for_the_node_service(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qnf-voice-test.mp3';
        file_put_contents($path, 'ID3fake-mp3-bytes');

        config([
            'services.whatsapp.active' => true,
            'services.whatsapp.group_id' => '120363407629757550@g.us',
            'services.whatsapp.url' => 'http://127.0.0.1:3001',
        ]);

        Http::fake([
            'http://127.0.0.1:3001/send-audio' => Http::response(['success' => true], 200),
        ]);

        try {
            $sent = app(WhatsAppService::class)->sendAudioToGroup($path, 'Time Azul');
        } finally {
            @unlink($path);
        }

        $this->assertTrue($sent);

        Http::assertSent(function ($request) use ($path) {
            return $request->url() === 'http://127.0.0.1:3001/send-audio'
                && $request['to'] === '120363407629757550@g.us'
                && $request['audioPath'] === $path
                && $request['audioFilename'] === 'qnf-voice-test.mp3'
                && $request['audioBase64'] === base64_encode('ID3fake-mp3-bytes')
                && $request['caption'] === 'Time Azul';
        });
    }
}
