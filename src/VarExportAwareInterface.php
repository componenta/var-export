<?php

declare(strict_types=1);

namespace Componenta\VarExport;

interface VarExportAwareInterface
{
    public function withVarExport(VarExport $varExport): static;
}
