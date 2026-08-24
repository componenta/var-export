<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Closure;
use Componenta\VarExport\ExportContext;

interface ContextualClosureExporterInterface extends ClosureExporterInterface
{
    /** @throws ExceptionInterface */
    public function exportWithContext(Closure $closure, ExportContext $context): string;
}
