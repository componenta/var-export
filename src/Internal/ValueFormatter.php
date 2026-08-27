<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Contract\ValueFormatterInterface;

/** @internal */
final readonly class ValueFormatter implements ValueFormatterInterface
{
    public function formatNumeric(int|float $value): string
    {
        if (is_float($value)) {
            if ($value === INF) {
                return '\\INF';
            }

            if ($value === -INF) {
                return '-\\INF';
            }

            if (is_nan($value)) {
                return '\\NAN';
            }

            return self::formatFiniteFloat($value);
        }

        return var_export($value, true);
    }

    public function escapeString(string $value): string
    {
        return var_export($value, true);
    }

    public function formatBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public function formatNull(): string
    {
        return 'null';
    }

    private static function formatFiniteFloat(float $value): string
    {
        $formatted = self::normalizeDecimalSeparator(sprintf('%.16G', $value));
        if ($value !== (float) $formatted) {
            $formatted = self::normalizeDecimalSeparator(sprintf('%.17G', $value));
        }

        return preg_match('/^-?[0-9]+$/D', $formatted) === 1
            ? $formatted . '.0'
            : $formatted;
    }

    private static function normalizeDecimalSeparator(string $value): string
    {
        $decimalPoint = localeconv()['decimal_point'] ?? '.';
        if ($decimalPoint === '' || $decimalPoint === '.') {
            return $value;
        }

        return str_replace($decimalPoint, '.', $value);
    }
}
