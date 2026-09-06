<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

final readonly class BoundClosureFactory
{
    public function make(): Closure
    {
        return fn (): string => $this::class;
    }
}
