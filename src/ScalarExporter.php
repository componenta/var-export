<?php

declare(strict_types=1);

namespace Componenta\VarExport;

final readonly class ScalarExporter implements VarExportInterface
{
    private const array TEXT_ESCAPES = [
        "\x00" => '\\x00',
        "\x01" => '\\x01',
        "\x02" => '\\x02',
        "\x03" => '\\x03',
        "\x04" => '\\x04',
        "\x05" => '\\x05',
        "\x06" => '\\x06',
        "\x07" => '\\x07',
        "\x08" => '\\x08',
        "\x09" => '\\t',
        "\x0A" => '\\n',
        "\x0B" => '\\v',
        "\x0C" => '\\f',
        "\x0D" => '\\r',
        "\x0E" => '\\x0E',
        "\x0F" => '\\x0F',
        "\x10" => '\\x10',
        "\x11" => '\\x11',
        "\x12" => '\\x12',
        "\x13" => '\\x13',
        "\x14" => '\\x14',
        "\x15" => '\\x15',
        "\x16" => '\\x16',
        "\x17" => '\\x17',
        "\x18" => '\\x18',
        "\x19" => '\\x19',
        "\x1A" => '\\x1A',
        "\x1B" => '\\e',
        "\x1C" => '\\x1C',
        "\x1D" => '\\x1D',
        "\x1E" => '\\x1E',
        "\x1F" => '\\x1F',
        "\x7F" => '\\x7F',
        "\x22" => '\\"',
        "\x24" => '\\$',
        "\x5C" => '\\\\',
    ];

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

        $formatted = sprintf('%.16H', $value);
        if ($value !== (float) $formatted) {
            $formatted = sprintf('%.17H', $value);
        }

        return preg_match('/^-?\d+$/D', $formatted) === 1
            ? $formatted . '.0'
            : $formatted;
    }

    private static function exportString(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return '"' . strtr($value, self::TEXT_ESCAPES) . '"';
        }

        static $binaryEscapes = null;
        if ($binaryEscapes === null) {
            $binaryEscapes = self::TEXT_ESCAPES;
            for ($byte = 128; $byte <= 255; ++$byte) {
                $binaryEscapes[chr($byte)] = sprintf('\\x%02X', $byte);
            }
        }

        return '"' . strtr($value, $binaryEscapes) . '"';
    }
}
