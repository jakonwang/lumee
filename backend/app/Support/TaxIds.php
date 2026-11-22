<?php

namespace App\Support;

/**
 * Personal / company tax IDs that customs and carriers need for parcels to some countries
 * (Brazil's CPF / CNPJ above all). Checkout asks for it when the destination needs it.
 */
final class TaxIds
{
    /** country => label shown at checkout */
    public const REQUIRED = [
        'BR' => 'CPF / CNPJ',
        'AR' => 'DNI / CUIT',
        'CL' => 'RUT',
        'CO' => 'Cédula / NIT',
        'PE' => 'DNI / RUC',
    ];

    public static function label(?string $country): ?string
    {
        return self::REQUIRED[strtoupper((string) $country)] ?? null;
    }

    /** Is the ID plausible for the country? Brazil is checked with the CPF / CNPJ check digits. */
    public static function valid(string $country, string $id): bool
    {
        $digits = preg_replace('/\D/', '', $id);

        return match (strtoupper($country)) {
            'BR' => strlen($digits) === 11 ? self::cpf($digits) : (strlen($digits) === 14 && self::cnpj($digits)),
            default => strlen(preg_replace('/[^0-9A-Za-z]/', '', $id)) >= 6,
        };
    }

    private static function cpf(string $d): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;   // 000.000.000-00 and friends
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * ($t + 1 - $i);
            }
            if ((int) $d[$t] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    private static function cnpj(string $d): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pos => $weights) {
            $sum = 0;
            foreach ($weights as $i => $w) {
                $sum += (int) $d[$i] * $w;
            }
            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $d[$pos] !== $check) {
                return false;
            }
        }

        return true;
    }
}
