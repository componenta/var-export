<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Closure;

interface ClosureExporterInterface
{
    /** @throws ExceptionInterface */
    public function export(Closure $closure): string;
}
