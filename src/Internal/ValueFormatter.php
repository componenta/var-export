<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Contract\ValueFormatterInterface;

/** @internal */
final readonly class ValueFormatter implements ValueFormatterInterface
{
    public function format(null|bool|int|float|string $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => var_export($value, true),
            is_float($value) => $this->formatFloat($value),
            default => var_export($value, true),
        };
    }

    private function formatFloat(float $value): string
    {
        if ($value === INF) {
            return '\\INF';
        }

        if ($value === -INF) {
            return '-\\INF';
        }

        if (is_nan($value)) {
            return '\\NAN';
        }

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
        $decimalPoint = localeconv()['decimal_point'];
        if ($decimalPoint === '' || $decimalPoint === '.') {
            return $value;
        }

        return str_replace($decimalPoint, '.', $value);
    }
}
