<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;

/**
 * Exports PHP objects to their string representation.
 */
interface ObjectExporterInterface
{
    /**
     * Export object to its string representation.
     *
     * @throws ExportException If object cannot be exported
     */
    public function export(object $object): string;

    /**
     * Export object with depth awareness for indentation.
     *
     * @throws ExportException If object cannot be exported
     */
    public function exportWithDepth(object $object, int $depth): string;

    /**
     * Check if this exporter supports the given object.
     */
    public function supports(object $object): bool;

    /**
     * Create new exporter instance with different configuration.
     */
    public function withConfig(ExportConfig $config): static;
}
