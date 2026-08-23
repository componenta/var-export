<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeVisitorAbstract;

/**
 * Freezes PHP's source-namespace function/constant fallback into explicit
 * fully-qualified names so generated closures keep the same lookup semantics
 * when evaluated from another namespace.
 *
 * @internal
 */
final class SourceSymbolResolver extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $node->name = $this->resolveFunction($node->name);
        } elseif ($node instanceof ConstFetch) {
            $node->name = $this->resolveConstant($node->name);
        }

        return null;
    }

    private function resolveFunction(Name $name): Name
    {
        if ($name instanceof FullyQualified || $name instanceof Name\Relative) {
            return $name;
        }

        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof Name && function_exists($namespaced->toString())) {
            return new FullyQualified($namespaced->toString());
        }

        if (!$name->isUnqualified()) {
            return $name;
        }

        return new FullyQualified($name->toString());
    }

    private function resolveConstant(Name $name): Name
    {
        if ($name instanceof FullyQualified || $name instanceof Name\Relative) {
            return $name;
        }

        $lower = strtolower($name->toString());
        if (in_array($lower, ['true', 'false', 'null'], true)) {
            return $name;
        }

        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof Name && defined($namespaced->toString())) {
            return new FullyQualified($namespaced->toString());
        }

        if (!$name->isUnqualified()) {
            return $name;
        }

        return new FullyQualified($name->toString());
    }
}
