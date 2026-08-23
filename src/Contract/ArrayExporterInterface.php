<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\Config\ExportConfig;

interface ArrayExporterInterface
{
    /**
     * @param array<mixed> $array
     * @throws ExceptionInterface
     */
    public function export(array $array): string;

    /**
     * Advanced composition API for exporters that render an array nested in
     * another generated expression.
     *
     * @param array<mixed> $array
     * @throws ExceptionInterface
     */
    public function exportAtDepth(array $array, int $depth, string $baseIndent): string;

    public function withConfig(ExportConfig $config): static;
}
