<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ArrayExportException;

/**
 * Exports PHP arrays to their string representation.
 */
interface ArrayExporterInterface
{
    /**
     * Export array to its string representation.
     *
     * @param array<mixed> $array The array to export
     * @return string The exported array as PHP code
     * @throws ArrayExportException If array cannot be exported
     */
    public function export(array $array): string;

    /**
     * Export an array as if it were already nested at the given depth.
     *
     * Used by other exporters (e.g. object exporter) that need to format
     * an array as a value inside a larger structure so indentation and
     * depth-based guards stay consistent.
     *
     * @param array<mixed> $array
     * @param int $depth Current nesting level of the surrounding structure
     * @param string $baseIndent Indent prefix for the surrounding level
     *                           (the array's closing bracket sits on this).
     * @throws ArrayExportException
     */
    public function exportAtDepth(array $array, int $depth, string $baseIndent): string;

    /**
     * Create new exporter instance with different configuration.
     */
    public function withConfig(ExportConfig $config): static;
}
