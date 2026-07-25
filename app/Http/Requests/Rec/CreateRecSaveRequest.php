<?php

namespace App\Http\Requests\Rec;

use Illuminate\Foundation\Http\FormRequest;

class CreateRecSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'capture_scope' => ['nullable', 'string', 'in:all,left,right'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
