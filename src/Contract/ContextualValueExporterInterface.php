<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\ExportContext;

interface ContextualValueExporterInterface
{
    /** @throws ExceptionInterface */
    public function exportValue(mixed $value, ExportContext $context): string;
}
