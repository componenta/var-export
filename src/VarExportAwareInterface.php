<?php

declare(strict_types=1);

namespace Componenta\VarExport;

interface VarExportAwareInterface
{
    /**
     * Return a bound copy without exporting values during the binding operation.
     */
    public function withVarExport(VarExport $varExport): static;
}
