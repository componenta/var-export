<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;

/**
 * Export any variable to its string representation.
 *
 * @param mixed $var The variable to export
 * @param ExportConfig|null $config Optional configuration
 * @param bool $pretty Whether to use pretty formatting (default: false)
 * @return string The exported variable as PHP code
 * @throws Exception\ExportException If the variable cannot be exported
 */
function var_export_string(mixed $var, ?ExportConfig $config = null, bool $pretty = false): string
{
    return $pretty
        ? Export::pretty($var, $config)
        : Export::var($var, $config);
}

/**
 * Export any variable with pretty formatting.
 *
 * @param mixed $var The variable to export
 * @param ExportConfig|null $config Optional configuration
 * @return string The exported variable as formatted PHP code
 * @throws Exception\ExportException If the variable cannot be exported
 */
function var_export_pretty(mixed $var, ?ExportConfig $config = null): string
{
    return Export::pretty($var, $config);
}

/**
 * Export an array to its string representation.
 *
 * @param array<mixed> $array The array to export
 * @param ExportConfig|null $config Optional configuration
 * @param bool $pretty Whether to use pretty formatting (default: false)
 * @return string The exported array as PHP code
 * @throws Exception\ArrayExportException If the array cannot be exported
 */
function array_export(array $array, ?ExportConfig $config = null, bool $pretty = false): string
{
    return $pretty
        ? Export::pretty($array, $config)
        : Export::array($array, $config);
}

/**
 * Export a closure to its string representation.
 *
 * @param Closure $closure The closure to export
 * @param ExportConfig|null $config Optional configuration
 * @param bool $pretty Whether to use pretty formatting (default: false)
 * @return string The exported closure as PHP code
 * @throws Exception\ClosureExportException If the closure cannot be exported
 */
function closure_export(Closure $closure, ?ExportConfig $config = null, bool $pretty = false): string
{
    return $pretty
        ? Export::pretty($closure, $config)
        : Export::closure($closure, $config);
}
