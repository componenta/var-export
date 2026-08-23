<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture\First {
    use Closure;

    final class Factory
    {
        public static function make(): Closure
        {
            return static fn(): string => __NAMESPACE__;
        }
    }
}

namespace Componenta\VarExport\Tests\Fixture\Second {
    use Closure;

    final class Factory
    {
        public static function make(): Closure
        {
            return static fn(): string => __NAMESPACE__;
        }
    }
}
