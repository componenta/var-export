<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;

/**
 * Exports PHP closures to their string representation.
 */
interface ClosureExporterInterface
{
    /**
     * Export closure to its string representation.
     *
     * @throws ClosureExportException If closure cannot be exported
     */
    public function export(Closure $closure): string;

    /**
     * Export closure with specific depth for proper indentation.
     *
     * @param int $depth Current nesting depth for indentation calculation
     * @throws ClosureExportException If closure cannot be exported
     */
    public function exportWithDepth(Closure $closure, int $depth): string;

    /**
     * Create new exporter instance with different configuration.
     */
    public function withConfig(ExportConfig $config): static;
}
