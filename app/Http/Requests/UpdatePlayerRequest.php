<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPlayerFields;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlayerRequest extends FormRequest
{
    use ValidatesPlayerFields;

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            ...$this->basePlayerRules($this->route('user')?->id),
            'active' => ['boolean'],
            'ability' => ['nullable', 'integer', 'min:1', 'max:10'],
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
