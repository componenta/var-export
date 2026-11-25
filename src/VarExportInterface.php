<?php

declare(strict_types=1);

namespace Componenta\VarExport;

interface VarExportInterface
{
    /**
     * Return a standalone PHP expression, or null when the value is unsupported.
     * A recognized but nonportable value should raise ExportException.
     */
    public function export(mixed $var): ?string;
}
