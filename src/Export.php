<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Exception\ExportException;

/**
 * Static facade for one-off variable exports.
 *
 * Provides convenient static methods for quick exports without
 * needing to instantiate VarExporter manually.
 *
 * For repeated exports with the same configuration, prefer using
 * VarExporter directly to benefit from AST caching.
 *
 * Example:
 * ```php
 * $code = Export::var(['key' => 'value']);
 * $code = Export::pretty($array);
 * $code = Export::closure(fn() => 42);
 * ```
 *
 * @see VarExporter
 */
final class Export
{
    /**
     * Prevent instantiation.
     */
    private function __construct() {}

    /**
     * Export any variable to its string representation.
     *
     * @throws ExportException If the variable cannot be exported
     */
    public static function var(mixed $var, ?ExportConfig $config = null): string
    {
        return self::createExporter($config)->export($var);
    }

    /**
     * Export with pretty formatting.
     *
     * Without a caller-supplied config this uses the full {@see ExportConfig::pretty()}
     * preset (multi-line + trailing comma), matching the layout shown in the README.
     * When the caller passes a config we respect their settings and only force the
     * formatter mode to pretty - otherwise we would silently override flags the
     * caller may have tuned on purpose.
     *
     * @throws ExportException If the variable cannot be exported
     */
    public static function pretty(mixed $var, ?ExportConfig $config = null): string
    {
        $config = $config !== null
            ? $config->withMode(FormatterMode::Pretty)
            : ExportConfig::pretty();

        return self::createExporter($config)->export($var);
    }

    /**
     * Export with trailing semicolon (for file output).
     *
     * @throws ExportException If the variable cannot be exported
     */
    public static function toFile(mixed $var, ?ExportConfig $config = null): string
    {
        return self::var($var, $config) . ';';
    }

    /**
     * Export an array.
     *
     * @param array<mixed> $array
     * @throws ExportException If the array cannot be exported
     */
    public static function array(array $array, ?ExportConfig $config = null): string
    {
        return self::var($array, $config);
    }

    /**
     * Export a closure.
     *
     * @throws ExportException If the closure cannot be exported
     */
    public static function closure(Closure $closure, ?ExportConfig $config = null): string
    {
        return self::var($closure, $config);
    }

    /**
     * Create a new VarExporter instance.
     *
     * VarExporter wires its own default ObjectExporter with a lazy
     * ArrayExporter provider so nested arrays inside readonly objects
     * respect the same pretty/sortKeys/trailingComma settings as the
     * outer structure. Callers that need a custom object strategy can
     * still instantiate {@see VarExporter} directly.
     */
    private static function createExporter(?ExportConfig $config): VarExporter
    {
        return new VarExporter($config ?? new ExportConfig());
    }
}
