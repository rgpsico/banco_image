<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CPF (11 dígitos) ou CNPJ (14) com dígitos verificadores corretos. Aceita pontuação. */
class CpfCnpj implements ValidationRule
{
    public static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    public static function isValid(string $value): bool
    {
        $d = self::digits($value);

        return match (strlen($d)) {
            11 => self::cpf($d),
            14 => self::cnpj($d),
            default => false,
        };
    }

    private static function cpf(string $d): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            if ((int) $d[$t] !== (($sum * 10) % 11) % 10) {
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
            $r = $sum % 11;
            if ((int) $d[$pos] !== ($r < 2 ? 0 : 11 - $r)) {
                return false;
            }
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('Informe um CPF ou CNPJ válido.');
        }
    }
}
