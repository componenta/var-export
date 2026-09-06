<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

class LateStaticScopeBase
{
    public static function make(): Closure
    {
        return static fn (): string => static::class;
    }
}
