<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

/**
 * Formats primitive values for PHP code export.
 */
interface ValueFormatterInterface
{
    /**
     * Format a numeric value (int or float) for export.
     *
     * Handles special cases like INF, -INF, and NAN.
     */
    public function formatNumeric(int|float $value): string;

    /**
     * Escape a string for safe inclusion in PHP code.
     *
     * Handles binary data and special characters appropriately.
     *
     * @return string The escaped string wrapped in quotes
     */
    public function escapeString(string $value): string;

    /**
     * Format a boolean value for export.
     */
    public function formatBool(bool $value): string;

    /**
     * Format a null value for export.
     */
    public function formatNull(): string;
}
