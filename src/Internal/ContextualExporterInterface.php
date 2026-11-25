<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\VarExportInterface;

/** @internal */
interface ContextualExporterInterface extends VarExportInterface
{
    public function exportInContext(mixed $var, ExportContext $context): ?string;
}
