<?php

namespace App\Http\Requests\Rec;

use Illuminate\Foundation\Http\FormRequest;

class StartRecSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'camera_tag' => ['required', 'string', 'in:A1,A2,B1,B2'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.mime_types' => ['nullable', 'array'],
            'capabilities.mime_type' => ['nullable', 'string', 'max:128'],
            'capabilities.width' => ['nullable', 'integer', 'min:1', 'max:7680'],
            'capabilities.height' => ['nullable', 'integer', 'min:1', 'max:4320'],
            'capabilities.fps' => ['nullable', 'integer', 'min:1', 'max:120'],
            'capabilities.has_audio' => ['nullable', 'boolean'],
            'client' => ['nullable', 'array'],
            'client.user_agent' => ['nullable', 'string', 'max:1024'],
            'client.timezone' => ['nullable', 'string', 'max:64'],
            'client.app_version' => ['nullable', 'string', 'max:64'],
            'client.device_fingerprint' => ['nullable', 'string', 'max:255'],
        ];
    }
}
