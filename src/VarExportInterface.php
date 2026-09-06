<?php

declare(strict_types=1);

namespace Componenta\VarExport;

interface VarExportInterface
{
    public function export(mixed $var): ?string;
}
