<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\Config\ExportConfig;

interface ObjectExporterInterface
{
    /** @throws ExceptionInterface */
    public function export(object $object): string;

    /** @throws ExceptionInterface */
    public function exportWithDepth(object $object, int $depth): string;

    /**
     * Exact preflight for the current instance. A true result means export()
     * completed successfully for that instance; false means it did not.
     */
    public function supports(object $object): bool;

    public function withConfig(ExportConfig $config): static;
}
