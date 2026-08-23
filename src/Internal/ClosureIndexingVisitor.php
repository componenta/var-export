<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Source\ClosureSourceCandidate;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class ClosureIndexingVisitor extends NodeVisitorAbstract
{
    /** @var array<int, list<ClosureSourceCandidate>> */
    private array $candidates = [];

    /** @var list<string> */
    private array $namespaceStack = [];

    /** @var list<string> */
    private array $traitStack = [];

    private string $namespace = '';
    private string $trait = '';

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->namespaceStack[] = $this->namespace;
            $this->namespace = $node->name?->toString() ?? '';

            return null;
        }

        if ($node instanceof Trait_) {
            $this->traitStack[] = $this->trait;
            $name = $node->name?->toString() ?? '';
            $this->trait = $this->namespace !== '' && $name !== ''
                ? $this->namespace . '\\' . $name
                : $name;

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->candidates[$node->getStartLine()][] = new ClosureSourceCandidate(
                $node,
                $this->namespace,
                $this->trait,
            );
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Trait_) {
            $this->trait = array_pop($this->traitStack) ?? '';
        } elseif ($node instanceof Namespace_) {
            $this->namespace = array_pop($this->namespaceStack) ?? '';
        }

        return null;
    }

    /** @return array<int, list<ClosureSourceCandidate>> */
    public function candidatesByLine(): array
    {
        return $this->candidates;
    }
}
