<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Source\ClosureSourceCandidate;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

/** @internal */
final class ClosureIndexingVisitor extends NodeVisitorAbstract
{
    public const string REFLECTION_START_LINE_ATTRIBUTE = 'componentaReflectionStartLine';

    /** @var array<int, list<ClosureSourceCandidate>> */
    private array $candidates = [];

    /** @var list<string> */
    private array $namespaceStack = [];

    /** @var list<string> */
    private array $traitStack = [];

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<string> */
    private array $classLikeFunctionStack = [];

    /** @var list<string> */
    private array $methodStack = [];

    /** @var list<string> */
    private array $propertyStack = [];

    /** @var list<int> */
    private array $closureScopeDepthStack = [];

    /** @var list<array{string, string, string, string}> */
    private array $functionScopeStack = [];

    private string $namespace = '';
    private string $trait = '';
    private string $class = '';
    private string $function = '';
    private string $method = '';
    private string $property = '';
    private int $closureDepth = 0;

    /** @param list<Token> $tokens */
    public function __construct(private readonly array $tokens = [])
    {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->namespaceStack[] = $this->namespace;
            $this->namespace = $node->name?->toString() ?? '';

            return null;
        }

        if ($node instanceof Trait_) {
            $this->traitStack[] = $this->trait;
            $this->classLikeFunctionStack[] = $this->function;
            $this->function = '';
            $name = $node->name?->toString() ?? '';
            $this->trait = $this->qualify($name);

            return null;
        }

        if ($node instanceof Class_ || $node instanceof Enum_) {
            $this->classStack[] = $this->class;
            $this->classLikeFunctionStack[] = $this->function;
            $this->function = '';
            $name = $node->name?->toString() ?? '';
            $this->class = $this->qualify($name);

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->methodStack[] = $this->method;
            $this->closureScopeDepthStack[] = $this->closureDepth;
            $this->method = $node->name->toString();
            $this->closureDepth = 0;

            return null;
        }

        if ($node instanceof Property) {
            $this->propertyStack[] = $this->property;
            $this->property = $node->props[0]->name->toString();

            return null;
        }

        if ($node instanceof PropertyHook) {
            $this->methodStack[] = $this->method;
            $this->closureScopeDepthStack[] = $this->closureDepth;
            $this->method = '$' . $this->property . '::' . $node->name->toString();
            $this->closureDepth = 0;

            return null;
        }

        if ($node instanceof Function_) {
            $this->functionScopeStack[] = [$this->class, $this->trait, $this->function, $this->method];
            $this->closureScopeDepthStack[] = $this->closureDepth;
            $this->class = '';
            $this->trait = '';
            $this->method = '';
            $this->function = $this->qualify($node->name->toString());
            $this->closureDepth = 0;

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            ++$this->closureDepth;
            $reflectionStartLine = $this->reflectionStartLine($node);
            $node->setAttribute(self::REFLECTION_START_LINE_ATTRIBUTE, $reflectionStartLine);
            $this->candidates[$reflectionStartLine][] = new ClosureSourceCandidate(
                $node,
                $this->namespace,
                $this->trait,
                $this->class,
                $this->function,
                $this->method,
                $this->closureDepth,
            );
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            --$this->closureDepth;
        } elseif ($node instanceof Function_) {
            [$this->class, $this->trait, $this->function, $this->method] = array_pop($this->functionScopeStack) ?? ['', '', '', ''];
            $this->closureDepth = array_pop($this->closureScopeDepthStack) ?? 0;
        } elseif ($node instanceof PropertyHook || $node instanceof ClassMethod) {
            $this->method = array_pop($this->methodStack) ?? '';
            $this->closureDepth = array_pop($this->closureScopeDepthStack) ?? 0;
        } elseif ($node instanceof Property) {
            $this->property = array_pop($this->propertyStack) ?? '';
        } elseif ($node instanceof Class_ || $node instanceof Enum_) {
            $this->class = array_pop($this->classStack) ?? '';
            $this->function = array_pop($this->classLikeFunctionStack) ?? '';
        } elseif ($node instanceof Trait_) {
            $this->trait = array_pop($this->traitStack) ?? '';
            $this->function = array_pop($this->classLikeFunctionStack) ?? '';
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

    private function reflectionStartLine(Closure|ArrowFunction $node): int
    {
        if ($node->attrGroups === [] || $this->tokens === []) {
            return $node->getStartLine();
        }

        $lastGroup = $node->attrGroups[array_key_last($node->attrGroups)];
        $tokenPosition = $lastGroup->getEndTokenPos();
        if ($tokenPosition < 0) {
            return $node->getStartLine();
        }

        $endTokenPosition = $node->getEndTokenPos();
        if ($endTokenPosition < $tokenPosition) {
            return $node->getStartLine();
        }

        $declarationToken = $node instanceof ArrowFunction ? T_FN : T_FUNCTION;
        for (++$tokenPosition; $tokenPosition <= $endTokenPosition; ++$tokenPosition) {
            $token = $this->tokens[$tokenPosition] ?? null;
            if ($token !== null && $token->id === $declarationToken) {
                return $token->line;
            }
        }

        return $node->getStartLine();
    }

    private function qualify(string $name): string
    {
        if ($name === '' || $this->namespace === '') {
            return $name;
        }

        return $this->namespace . '\\' . $name;
    }
}
