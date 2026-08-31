<?php

namespace App\Support;

class PersonName
{
    /**
     * @return array{first_name: string|null, last_name: string|null}
     */
    public static function split(?string $fullName): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $fullName));

        if ($normalized === '') {
            return ['first_name' => null, 'last_name' => null];
        }

        $parts = preg_split('/\s+/u', $normalized, 2) ?: [];

        return [
            'first_name' => $parts[0] ?? null,
            'last_name' => $parts[1] ?? null,
        ];
    }
}
