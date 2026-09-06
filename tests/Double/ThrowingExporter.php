<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

use Componenta\VarExport\VarExportInterface;
use Throwable;

final readonly class ThrowingExporter implements VarExportInterface
{
    public function __construct(private Throwable $failure)
    {
    }

    public function export(mixed $var): never
    {
        throw $this->failure;
    }
}
