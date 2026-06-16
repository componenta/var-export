<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Contract\ValueFormatterInterface;

/**
 * Formats primitive values for PHP code export.
 *
 * @internal This class is not part of the public API
 */
final readonly class ValueFormatter implements ValueFormatterInterface
{
    /**
     * Bytes that single-quoted literals cannot carry cleanly and therefore
     * force a switch to the double-quoted escape form. Printable ASCII,
     * \t / \n / \r and the entire high range (0x80-0xFF - i.e. valid UTF-8
     * continuations and 8-bit strings) are preserved as-is inside quotes so
     * multibyte text round-trips readably, exactly as PHP itself allows.
     */
    private const string BINARY_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    public function formatNumeric(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return match (true) {
            is_infinite($value) => $value > 0 ? 'INF' : '-INF',
            is_nan($value) => 'NAN',
            default => $this->formatFloat($value),
        };
    }

    public function escapeString(string $value): string
    {
        // Check for binary data or non-printable characters
        if (preg_match(self::BINARY_PATTERN, $value)) {
            return $this->escapeDoubleQuoted($value);
        }

        return $this->escapeSingleQuoted($value);
    }

    public function formatBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public function formatNull(): string
    {
        return 'null';
    }

    /**
     * Escape string using single quotes (preferred for simple strings).
     */
    private function escapeSingleQuoted(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /**
     * Escape string using double quotes (for binary/special characters).
     *
     * Handles NUL bytes, control characters, and other non-printable data.
     */
    private function escapeDoubleQuoted(string $value): string
    {
        $escaped = '';

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $char = $value[$i];
            $ord = ord($char);

            $escaped .= match ($char) {
                "\\" => '\\\\',
                '"' => '\\"',
                '$' => '\\$',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\v" => '\\v',
                "\f" => '\\f',
                "\e" => '\\e',
                "\0" => '\\0',
                default => $ord >= 0x20 && $ord <= 0x7E
                    ? $char
                    : sprintf('\\x%02X', $ord),
            };
        }

        return '"' . $escaped . '"';
    }

    /**
     * Format a float value ensuring proper representation.
     */
    private function formatFloat(float $value): string
    {
        $string = (string) $value;

        // Ensure float notation is preserved (e.g., "1.0" instead of "1")
        if (!str_contains($string, '.') && !str_contains($string, 'E')) {
            $string .= '.0';
        }

        return $string;
    }
}
