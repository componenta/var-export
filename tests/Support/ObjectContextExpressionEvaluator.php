<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Support;

use Closure;

final readonly class ObjectContextExpressionEvaluator
{
    public function evaluateClosure(string $expression): Closure
    {
        return evaluateClosureExpression($expression);
    }
}
