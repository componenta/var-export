<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\ExportContext;

interface ContextualObjectExporterInterface extends ObjectExporterInterface
{
    public function withValueExporter(ContextualValueExporterInterface $valueExporter): static;

    /** @throws ExceptionInterface */
    public function exportWithContext(object $object, ExportContext $context): string;
}
