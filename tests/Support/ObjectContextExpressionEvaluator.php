<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Support;

use Closure;

final readonly class ObjectContextExpressionEvaluator
{
    private const int PRIVATE_VALUE = 42;

    public static function privateValue(): int
    {
        return self::PRIVATE_VALUE;
    }

    public function evaluateClosure(string $expression): Closure
    {
        $value = eval('declare(strict_types=1); return ' . $expression . ';');
        if (!$value instanceof Closure) {
            throw new \LogicException('Expected the expression to evaluate to a Closure.');
        }

        return $value;
    }
}
