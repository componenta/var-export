<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

trait ScopedClosureTrait
{
    private const int OFFSET = 1;

    public static function makeFromTrait(): Closure
    {
        return static fn (int $value): int => self::OFFSET + $value;
    }
}
