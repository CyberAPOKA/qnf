<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPlayerFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlayerRequest extends FormRequest
{
    use ValidatesPlayerFields;

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        $rules = [
            ...$this->basePlayerRules($userId),
            'active' => ['boolean'],
            'guest' => ['boolean'],
            'ability' => ['nullable', 'integer', 'min:1', 'max:10'],
            ...$this->customizationRules(),
            ...$this->photoRules(),
        ];

        if ($this->boolean('guest')) {
            $unique = Rule::unique('users', 'phone');

            if ($userId) {
                $unique->ignore($userId);
            }

            $rules['phone'] = ['required', 'string', 'max:255', $unique];
        }

        return $rules;
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
