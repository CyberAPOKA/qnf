<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    public static function isValid(?string $phone): bool
    {
        return $phone !== null && preg_match('/^55\d{10}$/', $phone) === 1;
    }
}
