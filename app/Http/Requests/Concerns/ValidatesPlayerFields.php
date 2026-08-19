<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Position;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

trait ValidatesPlayerFields
{
    protected function basePlayerRules(?int $ignoreUserId = null): array
    {
        $instagramUnique = Rule::unique('users', 'instagram_username');

        if ($ignoreUserId) {
            $instagramUnique->ignore($ignoreUserId);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => $this->phoneRules($ignoreUserId),
            'position' => ['required', Rule::in(Position::values())],
            'instagram_username' => ['nullable', 'string', 'max:30', $instagramUnique, \App\Instagram\Support\InstagramUsernameNormalizer::rule()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => PhoneNumber::normalize($this->input('phone')),
            ]);
        }

        if ($this->exists('instagram_username')) {
            $raw = $this->input('instagram_username');

            if ($raw === null || trim((string) $raw) === '') {
                $this->merge(['instagram_username' => null]);
            } else {
                try {
                    $this->merge([
                        'instagram_username' => \App\Instagram\Support\InstagramUsernameNormalizer::normalize((string) $raw),
                    ]);
                } catch (\InvalidArgumentException) {
                    // Keep raw input so the validation rule can reject it.
                }
            }
        }

        if ($this->exists('customizations')) {
            $this->merge([
                'customizations' => $this->normalizeCustomizationsInput($this->input('customizations')),
            ]);
        }
    }

    protected function normalizeCustomizationsInput(mixed $raw): mixed
    {
        if ($raw === null || $raw === [] || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $raw;
        }

        return $decoded === [] ? null : $decoded;
    }

    protected function customizationRules(): array
    {
        return [
            'customizations' => ['nullable', 'array'],
            'customizations.flag' => ['nullable', 'in:B,L'],
        ];
    }

    protected function phoneRules(?int $ignoreUserId = null): array
    {
        $unique = Rule::unique('users', 'phone');

        if ($ignoreUserId) {
            $unique->ignore($ignoreUserId);
        }

        return ['required', 'regex:/^55\d{10}$/', $unique];
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
            'instagram_username.unique' => 'Este username do Instagram já está em uso.',
            'position.required' => 'Selecione a posição do jogador.',
            'position.in' => 'Selecione uma posição válida.',
            'password.required' => 'Informe a senha do jogador.',
            'password.min' => 'A senha deve ter pelo menos 3 caracteres.',
            'ability.integer' => 'A habilidade deve ser um número inteiro.',
            'ability.min' => 'A habilidade mínima é 1.',
            'ability.max' => 'A habilidade máxima é 10.',
            'customizations.array' => 'Informe um JSON válido.',
            'customizations.flag.in' => 'A flag deve ser B, L ou vazia.',
        ];
    }

    protected function playerAttributes(): array
    {
        return [
            'name' => 'nome',
            'phone' => 'telefone',
            'instagram_username' => 'instagram',
            'position' => 'posição',
            'password' => 'senha',
            'ability' => 'habilidade',
            'active' => 'ativo',
            'photo_front' => 'foto frente',
            'photo_side' => 'foto lado',
            'customizations' => 'customizações',
            'customizations.flag' => 'flag',
        ];
    }
}
