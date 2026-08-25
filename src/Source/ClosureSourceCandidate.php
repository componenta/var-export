<?php

declare(strict_types=1);

namespace Componenta\VarExport\Source;

use Componenta\VarExport\Internal\DetachedNodeCloner;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeTraverser;

/**
 * Source metadata for one closure/arrow-function AST candidate.
 */
final readonly class ClosureSourceCandidate
{
    public function __construct(
        public Closure|ArrowFunction $node,
        public string $namespace = '',
        public string $trait = '',
        public string $class = '',
        public string $function = '',
        public string $method = '',
        public string $property = '',
        public string $propertyHook = '',
    ) {
    }

    public function detached(): self
    {
        $traverser = new NodeTraverser(new DetachedNodeCloner());
        /** @var array{0: Closure|ArrowFunction} $cloned */
        $cloned = $traverser->traverse([$this->node]);

        return new self(
            $cloned[0],
            $this->namespace,
            $this->trait,
            $this->class,
            $this->function,
            $this->method,
            $this->property,
            $this->propertyHook,
        );
    }
}
