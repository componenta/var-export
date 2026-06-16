<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;

/**
 * Replaces file-level magic constants (__FILE__, __DIR__, __NAMESPACE__, __LINE__)
 * with their literal values, so the exported closure is self-contained and does
 * not depend on the location it is evaluated in.
 *
 * Class/method/function/trait magic constants are replaced with an empty string:
 * their value depends on an enclosing class or function scope that no longer
 * exists once the closure is printed in isolation.
 *
 * Regular name resolution (unqualified class/function/constant references) is
 * handled at parse time by {@see \PhpParser\NodeVisitor\NameResolver}. This
 * visitor deliberately does not touch those nodes.
 *
 * @internal This class is not part of the public API
 */
final class MagicConstantResolver extends NodeVisitorAbstract
{
    public function __construct(
        private readonly ?Namespace_ $namespace,
        private readonly string $filename,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof MagicConst) {
            return null;
        }

        return match (true) {
            $node instanceof MagicConst\File => new String_($this->filename),
            $node instanceof MagicConst\Dir => new String_(dirname($this->filename)),
            $node instanceof MagicConst\Namespace_ => new String_(
                $this->namespace?->name?->toString() ?? '',
            ),
            $node instanceof MagicConst\Line => new Node\Scalar\Int_($node->getStartLine()),
            // No enclosing class/function context survives once the closure is
            // printed standalone, so these resolve to an empty string - the same
            // value PHP reports for them at file top level.
            $node instanceof MagicConst\Class_,
            $node instanceof MagicConst\Method,
            $node instanceof MagicConst\Function_,
            $node instanceof MagicConst\Trait_ => new String_(''),
            default => null,
        };
    }
}
