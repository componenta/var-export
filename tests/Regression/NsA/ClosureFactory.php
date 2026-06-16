<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression\NsA;

use Closure;
use SplObjectStorage;

/**
 * Helper that produces closures defined inside this namespace so
 * cross-namespace export/eval scenarios can be exercised from tests
 * living in a different namespace.
 */
final class ClosureFactory
{
    public static function sumClosure(): Closure
    {
        return static fn(array $xs): int => array_sum($xs);
    }

    public static function splStorageFactoryClosure(): Closure
    {
        return static fn(): SplObjectStorage => new SplObjectStorage();
    }
}
