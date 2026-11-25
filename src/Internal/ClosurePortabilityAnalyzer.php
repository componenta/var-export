<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ExportException;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeVisitorAbstract;
use ReflectionFunction;

final class ClosurePortabilityAnalyzer extends NodeVisitorAbstract
{
    /** @var array<string, mixed>|null */
    private ?array $userConstants = null;

    public function __construct(
        private readonly string $filename,
        private readonly int $line,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof InlineHTML) {
            throw $this->failure('PHP mode switches are not valid inside a standalone expression', $node);
        }

        if ($node instanceof Function_ || $node instanceof ClassLike) {
            throw $this->failure('nested named declarations depend on their source environment', $node);
        }

        if ($node instanceof Static_) {
            throw $this->failure('static local state cannot be reproduced safely', $node);
        }

        if ($node instanceof Include_) {
            throw $this->failure('include/require depends on the generated artifact location', $node);
        }

        if ($node instanceof Eval_) {
            throw $this->failure('eval() depends on its source file and scope context', $node);
        }

        if ($node instanceof MagicConst) {
            throw $this->failure(sprintf('%s is source-context dependent', $node->getName()), $node);
        }

        if ($node instanceof Variable) {
            if (!is_string($node->name)) {
                throw $this->failure('dynamic variable access cannot be frozen safely', $node);
            }

            if ($node->name === 'this') {
                throw $this->failure('$this depends on object binding', $node);
            }
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $node->name = $this->resolveFunction($node->name, $node);
        }

        if ($node instanceof ConstFetch) {
            $node->name = $this->resolveConstant($node->name, $node);
        }

        return null;
    }

    private function resolveFunction(Name $name, Node $node): FullyQualified
    {
        $resolved = $this->resolvedName($name, true);
        if (self::hasNamespaceFallback($name)) {
            if (!function_exists($resolved)) {
                throw $this->failure(
                    sprintf('unresolved unqualified function "%s" has namespace fallback semantics', $name),
                    $node,
                );
            }

            // PHP may already have cached a global fallback before the
            // namespaced function was declared. Reflection cannot reveal that
            // cached target; choosing either existing function would be unsafe.
            if ($resolved !== $name->toString() && function_exists($name->toString())) {
                throw $this->failure(
                    sprintf('ambiguous unqualified function "%s" has namespace fallback semantics', $name),
                    $node,
                );
            }
        }

        if (function_exists($resolved)) {
            $reflection = new ReflectionFunction($resolved);
            $definedIn = $reflection->getFileName();

            if (
                $definedIn !== false
                && self::canonicalPath($definedIn) === self::canonicalPath($this->filename)
            ) {
                throw $this->failure(
                    sprintf('function "%s" is defined in the closure provider source file', $resolved),
                    $node,
                );
            }
        }

        return new FullyQualified($resolved);
    }

    private function resolveConstant(Name $name, Node $node): Name
    {
        if (in_array(strtolower($name->toString()), ['true', 'false', 'null'], true)) {
            return $name;
        }

        $resolved = $this->resolvedName($name, false);
        if (self::hasNamespaceFallback($name) && !defined($resolved)) {
            throw $this->failure(
                sprintf('unresolved unqualified constant "%s" has namespace fallback semantics', $name),
                $node,
            );
        }

        $this->userConstants ??= get_defined_constants(true)['user'] ?? [];

        if (array_key_exists($resolved, $this->userConstants)) {
            throw $this->failure(
                sprintf('runtime user-defined constant "%s" is not portable', $resolved),
                $node,
            );
        }

        return new FullyQualified($resolved);
    }

    private function resolvedName(Name $name, bool $function): string
    {
        if ($name instanceof FullyQualified) {
            return $name->toString();
        }

        $namespaced = $name->getAttribute('namespacedName');
        if (
            $namespaced instanceof Name
            && ($function ? function_exists($namespaced->toString()) : defined($namespaced->toString()))
        ) {
            return $namespaced->toString();
        }

        return $name->toString();
    }

    private static function hasNamespaceFallback(Name $name): bool
    {
        if (!$name->isUnqualified()) {
            return false;
        }

        $namespaced = $name->getAttribute('namespacedName');

        return $namespaced instanceof Name
            && $namespaced->toString() !== $name->toString();
    }

    private function failure(string $reason, Node $node): ExportException
    {
        return ExportException::nonPortableClosure(
            $reason,
            $this->filename,
            $node->getStartLine() ?: $this->line,
        );
    }

    private static function canonicalPath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
