<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

use Closure;
use Componenta\VarExport\VarExportInterface;

final readonly class CallbackExporter implements VarExportInterface
{
    /** @param Closure(mixed): (?string) $callback */
    public function __construct(private Closure $callback)
    {
    }

    public function export(mixed $var): ?string
    {
        return ($this->callback)($var);
    }
}
