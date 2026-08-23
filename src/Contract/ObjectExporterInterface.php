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
     * Structural/source-generation preflight for the current instance.
     *
     * A true result means this exporter can produce PHP source for the current
     * instance. It does not execute the generated expression and therefore
     * cannot prove that replaying an opted-in user constructor is side-effect
     * free or accepts reflection/unserialization-hydrated state.
     */
    public function supports(object $object): bool;

    public function withConfig(ExportConfig $config): static;
}
