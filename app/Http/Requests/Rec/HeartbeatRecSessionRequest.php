<?php

namespace App\Http\Requests\Rec;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRecSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_sent_at_ms' => ['nullable', 'integer'],
            'buffer_available_ms' => ['nullable', 'integer', 'min:0'],
            'last_segment_sequence' => ['nullable', 'integer', 'min:0'],
            'queue_size' => ['nullable', 'integer', 'min:0'],
            'camera_state' => ['nullable', 'string', 'max:64'],
            'battery_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'local_storage_bytes' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
