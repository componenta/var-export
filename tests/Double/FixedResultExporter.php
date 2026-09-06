<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

use Componenta\VarExport\VarExportInterface;

final readonly class FixedResultExporter implements VarExportInterface
{
    public function __construct(private string $result)
    {
    }

    public function export(mixed $var): string
    {
        return $this->result;
    }
}
