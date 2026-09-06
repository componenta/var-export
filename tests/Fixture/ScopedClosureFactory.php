<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

final readonly class ScopedClosureFactory
{
    private const int OFFSET = 1;

    public static function make(): Closure
    {
        return static fn (int $value): int => self::OFFSET + $value;
    }

    public static function makeNonStatic(): Closure
    {
        return fn (int $value): int => self::OFFSET + $value;
    }
}
