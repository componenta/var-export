<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

function unscopedPrivateConstantClosure(): Closure
{
    return static fn (string $class): mixed => constant($class . '::PRIVATE_VALUE');
}
