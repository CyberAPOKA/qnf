<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Position;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

trait ValidatesPlayerFields
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => PhoneNumber::normalize($this->input('phone')),
            ]);
        }
    }

    protected function phoneRules(?int $ignoreUserId = null): array
    {
        $unique = Rule::unique('users', 'phone');

        if ($ignoreUserId) {
            $unique->ignore($ignoreUserId);
        }

        return ['required', 'regex:/^55\d{10}$/', $unique];
    }

    protected function basePlayerRules(?int $ignoreUserId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => $this->phoneRules($ignoreUserId),
            'position' => ['required', Rule::in(Position::values())],
        ];
    }

    protected function photoRules(): array
    {
        return [
            'photo_front' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'photo_side' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    protected function playerMessages(): array
    {
        return [
            'name.required' => 'Informe o nome do jogador.',
            'phone.required' => 'Informe o telefone do jogador.',
            'phone.regex' => 'Informe um telefone válido com DDD e número (ex.: 555199294672 ou +55 51 9929-4672).',
            'phone.unique' => 'Este telefone já está cadastrado.',
            'position.required' => 'Selecione a posição do jogador.',
            'position.in' => 'Selecione uma posição válida.',
            'password.required' => 'Informe a senha do jogador.',
            'password.min' => 'A senha deve ter pelo menos 3 caracteres.',
            'ability.integer' => 'A habilidade deve ser um número inteiro.',
            'ability.min' => 'A habilidade mínima é 1.',
            'ability.max' => 'A habilidade máxima é 10.',
        ];
    }

    protected function playerAttributes(): array
    {
        return [
            'name' => 'nome',
            'phone' => 'telefone',
            'position' => 'posição',
            'password' => 'senha',
            'ability' => 'habilidade',
            'active' => 'ativo',
            'photo_front' => 'foto frente',
            'photo_side' => 'foto lado',
        ];
    }
}
