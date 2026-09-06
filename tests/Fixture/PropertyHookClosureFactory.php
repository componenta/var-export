<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

final class PropertyHookClosureFactory
{
    public Closure $callback {
        get => static fn (int $value): int => $value + 1;
    }
}
