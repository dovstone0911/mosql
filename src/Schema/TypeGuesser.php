<?php

namespace Dovstone\MoSQL\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * Détection automatique des types SQL
 */
class TypeGuesser
{
    public static function guess($value): string
    {
        if ($value === null) {
            return 'string';
        }

        $type = gettype($value);

        return match ($type) {
            'integer' => 'integer',
            'double' => 'float',
            'boolean' => 'boolean',
            'array', 'object' => 'json',
            'string' => self::guessStringType($value),
            default => 'string',
        };
    }

    private static function guessStringType(string $value): string
    {
        if (strtotime($value) !== false) {
            return 'datetime';
        }
        if (strlen($value) > 255) {
            return 'text';
        }
        return 'string';
    }

    public static function guessLength($value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $len = strlen($value);
        if ($len > 255) {
            return null;
        }
        if ($len > 100) {
            return 255;
        }
        if ($len > 50) {
            return 100;
        }
        return 50;
    }
}
