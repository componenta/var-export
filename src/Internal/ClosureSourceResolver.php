<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ExportException;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use ReflectionFunction;
use Throwable;

/** @internal */
final class ClosureSourceResolver
{
    private const int MAX_CACHED_FILES = 4;
    private const int MAX_CACHED_SOURCE_BYTES = 262144;

    private readonly ClosureMetadataMatcher $matcher;

    /** @var array<string, ClosureSourceSnapshot> */
    private array $cache = [];

    public function __construct()
    {
        $this->matcher = new ClosureMetadataMatcher();
    }

    /** @return array{ClosureNode|ArrowFunction, bool} */
    public function resolve(ReflectionFunction $reflection): array
    {
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if ($filename === false || $startLine === false || $endLine === false) {
            throw new ExportException('Closure source location is unavailable.');
        }

        $source = WarningGuard::run(static fn (): string|false => file_get_contents($filename));
        if ($source === false) {
            throw new ExportException(
                sprintf('Cannot read closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $startLine,
            );
        }

        $snapshot = $this->snapshot($filename, $source, $startLine);
        $candidates = $snapshot->candidatesAt($startLine, $endLine);

        if ($candidates === []) {
            throw new ExportException(
                sprintf(
                    'Expected one closure expression at %s:%d, found %d.',
                    $filename,
                    $startLine,
                    count($candidates),
                ),
                ['filename' => $filename, 'line' => $startLine, 'candidates' => count($candidates)],
                $filename,
                $startLine,
            );
        }

        $matches = [];
        $deferredError = null;
        foreach ($candidates as $candidate) {
            try {
                if ($this->matcher->matches($candidate, $reflection)) {
                    $matches[] = $candidate;
                }
            } catch (ExportException $error) {
                $deferredError ??= $error;
            }
        }

        if ($matches === [] && $deferredError !== null) {
            throw $deferredError;
        }

        if ($matches === []) {
            throw new ExportException(
                'Closure source no longer matches the runtime closure metadata.',
                ['filename' => $filename, 'line' => $startLine],
                $filename,
                $startLine,
            );
        }

        if (count($matches) !== 1) {
            throw new ExportException(
                sprintf(
                    'Expected one closure expression at %s:%d, found %d.',
                    $filename,
                    $startLine,
                    count($matches),
                ),
                ['filename' => $filename, 'line' => $startLine, 'candidates' => count($matches)],
                $filename,
                $startLine,
            );
        }

        $strictTypes = self::sourceUsesStrictTypes($snapshot->declarations(), $matches[0], $reflection);
        // Name resolution and portability analysis may mutate the selected expression.
        // Never pass cached nodes to the expression builder.
        $nodes = (new NodeTraverser(new CloningVisitor()))->traverse([$matches[0]]);
        $node = $nodes[0] ?? null;
        if (!$node instanceof ClosureNode && !$node instanceof ArrowFunction) {
            throw new \LogicException('Unable to copy the resolved closure expression.');
        }

        return [$node, $strictTypes];
    }

    private function snapshot(string $filename, string $source, int $startLine): ClosureSourceSnapshot
    {
        $cached = $this->cache[$filename] ?? null;
        unset($this->cache[$filename]);
        if ($cached !== null && $cached->matchesSource($source)) {
            $this->cache[$filename] = $cached;

            return $cached;
        }

        try {
            $statements = (new ParserFactory())
                ->createForHostVersion()
                ->parse($source);
        } catch (Throwable $error) {
            throw new ExportException(
                sprintf('Cannot parse closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $startLine,
                $error,
            );
        }

        if ($statements === null) {
            throw new ExportException(
                sprintf('Cannot parse closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $startLine,
            );
        }

        try {
            $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
        } catch (ParserError $error) {
            throw new ExportException(
                sprintf('Cannot resolve names in closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $error->getStartLine() > 0 ? $error->getStartLine() : $startLine,
                $error,
            );
        }

        $statements = (new NodeTraverser(new ParentConnectingVisitor()))->traverse($statements);
        $snapshot = new ClosureSourceSnapshot($source, $statements);
        if (strlen($source) <= self::MAX_CACHED_SOURCE_BYTES) {
            if (count($this->cache) >= self::MAX_CACHED_FILES) {
                array_shift($this->cache);
            }

            $this->cache[$filename] = $snapshot;
        }

        return $snapshot;
    }

    /** @param list<Declare_> $declarations */
    private static function sourceUsesStrictTypes(
        array $declarations,
        ClosureNode|ArrowFunction $closure,
        ReflectionFunction $reflection,
    ): bool {
        $strictTypes = false;
        $externalTicks = null;

        foreach ($declarations as $declaration) {
            foreach ($declaration->declares as $declare) {
                $name = strtolower($declare->key->toString());
                if ($name === 'strict_types') {
                    $strictTypes = $declare->value instanceof Int_
                        && $declare->value->value === 1;

                    continue;
                }

                if (
                    self::nodeHasAncestor($declaration, $closure)
                    || !self::declarationAffectsNode($declaration, $closure)
                ) {
                    continue;
                }

                if ($name === 'ticks') {
                    $value = $declare->value;
                    $isDisabled = ($value instanceof Int_ || $value instanceof Float_ || $value instanceof String_)
                        && (int) $value->value === 0;
                    $externalTicks = $isDisabled ? null : $declaration;

                    continue;
                }

                throw ExportException::nonPortableClosure(
                    sprintf('declare(%s) cannot be preserved outside the closure expression', $name),
                    $reflection->getFileName() ?: null,
                    $declaration->getStartLine() ?: $reflection->getStartLine() ?: null,
                );
            }
        }

        if ($externalTicks !== null) {
            throw ExportException::nonPortableClosure(
                'declare(ticks) cannot be preserved outside the closure expression',
                $reflection->getFileName() ?: null,
                $externalTicks->getStartLine() ?: $reflection->getStartLine() ?: null,
            );
        }

        return $strictTypes;
    }

    private static function declarationAffectsNode(Declare_ $declaration, Node $node): bool
    {
        if ($declaration->stmts !== null) {
            return self::nodeHasAncestor($node, $declaration);
        }

        $declarationPosition = $declaration->getStartFilePos();
        $nodePosition = $node->getStartFilePos();
        if ($declarationPosition >= 0 && $nodePosition >= 0 && $declarationPosition > $nodePosition) {
            return false;
        }

        // A semicolon declaration changes the compiler state until the end of an
        // enclosing scoped declare, not the end of a namespace, function or if.
        $parent = $declaration->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof Declare_ && $parent->stmts !== null
                && !self::nodeHasAncestor($node, $parent)
            ) {
                return false;
            }

            $parent = $parent->getAttribute('parent');
        }

        return true;
    }

    private static function nodeHasAncestor(Node $node, Node $ancestor): bool
    {
        $current = $node;

        while (true) {
            if ($current === $ancestor) {
                return true;
            }

            $parent = $current->getAttribute('parent');
            if (!$parent instanceof Node) {
                return false;
            }

            $current = $parent;
        }
    }
}
