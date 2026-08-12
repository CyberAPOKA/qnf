<?php

namespace App\Http\Requests\Rec;

use Illuminate\Foundation\Http\FormRequest;

class UploadRecSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sequence' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'uuid' => ['nullable', 'uuid'],
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
            'client_started_at' => ['nullable', 'date'],
            'client_ended_at' => ['nullable', 'date'],
            'estimated_server_started_at' => ['nullable', 'date'],
            'estimated_server_ended_at' => ['nullable', 'date'],
            'duration_ms' => ['nullable', 'integer', 'min:1', 'max:180000'],
            'mime_type' => ['nullable', 'string', 'max:128'],
            'container' => ['nullable', 'string', 'max:32'],
            'video_codec' => ['nullable', 'string', 'max:64'],
            'audio_codec' => ['nullable', 'string', 'max:64'],
            'segment' => ['nullable', 'file', 'max:51200'],
            'video' => ['nullable', 'file', 'max:51200'],
        ];
    }
}
