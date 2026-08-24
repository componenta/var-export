<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Source\ClosureSourceCandidate;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
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

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<array{string, string, string}> */
    private array $functionScopeStack = [];

    private string $namespace = '';
    private string $trait = '';
    private string $class = '';
    private string $function = '';

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
            $this->trait = $this->qualify($name);

            return null;
        }

        if ($node instanceof Class_ || $node instanceof Enum_) {
            $this->classStack[] = $this->class;
            $name = $node->name?->toString() ?? '';
            $this->class = $this->qualify($name);

            return null;
        }

        if ($node instanceof Function_) {
            $this->functionScopeStack[] = [$this->class, $this->trait, $this->function];
            $this->class = '';
            $this->trait = '';
            $this->function = $this->qualify($node->name->toString());

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->candidates[$node->getStartLine()][] = new ClosureSourceCandidate(
                $node,
                $this->namespace,
                $this->trait,
                $this->class,
                $this->function,
            );
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Function_) {
            [$this->class, $this->trait, $this->function] = array_pop($this->functionScopeStack) ?? ['', '', ''];
        } elseif ($node instanceof Class_ || $node instanceof Enum_) {
            $this->class = array_pop($this->classStack) ?? '';
        } elseif ($node instanceof Trait_) {
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

    private function qualify(string $name): string
    {
        if ($name === '' || $this->namespace === '') {
            return $name;
        }

        return $this->namespace . '\\' . $name;
    }
}
