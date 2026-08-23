<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ClosureExportException;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;

/**
 * Builds a self-contained creator expression instead of replacing variable
 * occurrences. This preserves lvalue, nested-scope and by-value capture
 * semantics of the original closure body.
 *
 * @internal
 */
final readonly class UseVariableInliner
{
    /**
     * @param array<string, mixed> $variables
     * @throws ClosureExportException
     */
    public function inline(
        Closure|ArrowFunction $closure,
        array $variables,
        int $maxDepth,
        ?string $filename = null,
        ?int $line = null,
    ): Node\Expr {
        if ($variables === []) {
            return $closure;
        }

        $statements = [];
        foreach ($variables as $name => $value) {
            $statements[] = new Expression(new Assign(
                new Variable($name),
                UseVariableValueNodeFactory::fromValue(
                    $value,
                    $name,
                    $maxDepth,
                    filename: $filename,
                    line: $line,
                ),
            ));
        }

        $statements[] = new Return_($closure);

        $factory = new Closure([
            'static' => true,
            'params' => [],
            'uses' => [],
            'stmts' => $statements,
        ]);

        return new FuncCall($factory);
    }
}
