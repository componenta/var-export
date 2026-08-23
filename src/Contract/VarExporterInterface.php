<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\Config\ExportConfig;

interface VarExporterInterface
{
    /** @throws ExceptionInterface */
    public function export(mixed $var): string;

    /** @throws ExceptionInterface */
    public function exportToFile(mixed $var): string;

    public function withConfig(ExportConfig $config): static;

    public function getConfig(): ExportConfig;
}
