<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

function unscopedPrivateConstantClosure(): Closure
{
    return static fn (string $class): int => $class::PRIVATE_VALUE;
}
