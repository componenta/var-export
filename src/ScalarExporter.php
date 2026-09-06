<?php

declare(strict_types=1);

namespace Componenta\VarExport;

final readonly class ScalarExporter implements VarExportInterface
{
    public function export(mixed $var): ?string
    {
        return match (true) {
            $var === null => 'null',
            is_bool($var) => $var ? 'true' : 'false',
            is_int($var) => self::exportInt($var),
            is_float($var) => self::exportFloat($var),
            is_string($var) => self::exportString($var),
            default => null,
        };
    }

    private static function exportInt(int $value): string
    {
        return $value === PHP_INT_MIN
            ? (string) ($value + 1) . ' - 1'
            : (string) $value;
    }

    private static function exportFloat(float $value): string
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

        $formatted = str_replace(',', '.', sprintf('%.16G', $value));
        if ($value !== (float) $formatted) {
            $formatted = str_replace(',', '.', sprintf('%.17G', $value));
        }

        return preg_match('/^-?\d+$/D', $formatted) === 1
            ? $formatted . '.0'
            : $formatted;
    }

    private static function exportString(string $value): string
    {
        $isUtf8 = preg_match('//u', $value) === 1;
        $expression = '"';

        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $byte = ord($value[$index]);

            $expression .= match ($byte) {
                0x09 => '\\t',
                0x0A => '\\n',
                0x0B => '\\v',
                0x0C => '\\f',
                0x0D => '\\r',
                0x1B => '\\e',
                0x22 => '\\"',
                0x24 => '\\$',
                0x5C => '\\\\',
                default => self::exportStringByte($value[$index], $byte, $isUtf8),
            };
        }

        return $expression . '"';
    }

    private static function exportStringByte(string $character, int $byte, bool $isUtf8): string
    {
        if ($byte >= 0x20 && $byte <= 0x7E) {
            return $character;
        }

        if ($byte >= 0x80 && $isUtf8) {
            return $character;
        }

        return sprintf('\\x%02X', $byte);
    }

}
