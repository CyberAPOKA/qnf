<?php

namespace App\Support;

use App\Models\User;

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

    public static function digits(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }

        $value = str_contains($phone, '@')
            ? (strstr($phone, '@', true) ?: $phone)
            : $phone;

        return preg_replace('/\D+/', '', $value) ?: '';
    }

    public static function lastEight(?string $phone): ?string
    {
        $digits = self::digits($phone);

        if (strlen($digits) < 8) {
            return null;
        }

        return substr($digits, -8);
    }

    public static function findUser(?string $phone): ?User
    {
        $digits = self::digits($phone);

        if ($digits === '') {
            return null;
        }

        $exact = User::query()->where('phone', $digits)->first();

        if ($exact) {
            return $exact;
        }

        if (! str_starts_with($digits, '55')) {
            $withCountry = User::query()->where('phone', '55'.$digits)->first();

            if ($withCountry) {
                return $withCountry;
            }
        }

        $lastEight = self::lastEight($digits);

        if ($lastEight === null) {
            return null;
        }

        return User::query()
            ->whereRaw('SUBSTR(phone, -8) = ?', [$lastEight])
            ->first();
    }
}
