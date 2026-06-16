<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeTraverser;

/**
 * Inlines captured use() variables into closure body.
 *
 * Transforms:
 *   $x = 42; $fn = function() use ($x) { return $x; }
 * Into:
 *   function() { return 42; }
 *
 * Note: Nested closures in use() variables are NOT supported.
 * The ClosureValidator should reject such cases before reaching this class.
 *
 * @internal This class is not part of the public API
 */
final readonly class UseVariableInliner
{
    /**
     * Inline use variables into a closure node.
     *
     * @param array<string, mixed> $variables Variable name => value map
     * @return Closure|ArrowFunction Modified closure node
     */
    public function inline(Closure|ArrowFunction $closureNode, array $variables): Closure|ArrowFunction
    {
        if ($variables === []) {
            return $closureNode;
        }

        $useNames = $this->extractUseVariableNames($closureNode, $variables);

        // Only values that will actually be referenced need to be converted
        // to AST nodes - saves work when use() declares a variable that is
        // unused in the body.
        $replacements = [];
        foreach ($useNames as $name) {
            if (array_key_exists($name, $variables)) {
                $replacements[$name] = UseVariableValueNodeFactory::fromValue($variables[$name]);
            }
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new UseVariableInliningVisitor($replacements));

        /** @var array<Closure|ArrowFunction> $result */
        $result = $traverser->traverse([$closureNode]);

        return $this->removeUseClause($result[0]);
    }

    /**
     * Remove the use() clause from a closure.
     */
    private function removeUseClause(Closure|ArrowFunction $node): Closure|ArrowFunction
    {
        if ($node instanceof Closure) {
            return new Closure([
                'static' => $node->static,
                'byRef' => $node->byRef,
                'params' => $node->params,
                'uses' => [],
                'returnType' => $node->returnType,
                'stmts' => $node->stmts,
                'attrGroups' => $node->attrGroups,
            ]);
        }

        return $node;
    }

    /**
     * Extract variable names from use() clause.
     *
     * For arrow functions (which have implicit capture) we fall back to
     * the variable names provided by reflection.
     *
     * @param array<string, mixed> $variables
     * @return array<int, string>
     */
    private function extractUseVariableNames(Closure|ArrowFunction $node, array $variables): array
    {
        if ($node instanceof ArrowFunction) {
            return array_keys($variables);
        }

        $names = [];
        foreach ($node->uses as $use) {
            if (is_string($use->var->name)) {
                $names[] = $use->var->name;
            }
        }

        return $names;
    }
}
