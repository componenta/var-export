<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Replaces variable references within a closure body with pre-built AST
 * nodes for their captured values.
 *
 * Carries no mutable state between runs: each instance is configured once
 * via the constructor and discarded after traversal.
 *
 * @internal This class is not part of the public API
 */
final class UseVariableInliningVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, Node\Expr> $replacements Variable name => AST node
     */
    public function __construct(private readonly array $replacements) {}

    public function enterNode(Node $node): int|Node|null
    {
        // Names inside the use() clause itself must not be rewritten -
        // they are declarations, not references.
        if ($node instanceof ClosureUse) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if (!$node instanceof Variable || !is_string($node->name)) {
            return null;
        }

        return $this->replacements[$node->name] ?? null;
    }
}
