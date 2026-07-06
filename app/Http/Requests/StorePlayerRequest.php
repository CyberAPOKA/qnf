<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPlayerFields;
use Illuminate\Foundation\Http\FormRequest;

class StorePlayerRequest extends FormRequest
{
    use ValidatesPlayerFields;

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            ...$this->basePlayerRules(),
            'password' => ['required', 'string', 'min:3'],
            'active' => ['boolean'],
            ...$this->photoRules(),
        ];
    }

    public function messages(): array
    {
        return $this->playerMessages();
    }

    public function attributes(): array
    {
        return $this->playerAttributes();
    }
}
