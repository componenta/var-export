<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Closure;
use Componenta\VarExport\Exception\ExportException;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use ReflectionFunction;
use Throwable;

/** @internal */
final class ClosureSourceResolver
{
    private readonly ClosureMetadataMatcher $matcher;

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

        $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
        $statements = (new NodeTraverser(new ParentConnectingVisitor()))->traverse($statements);
        $nodes = (new NodeFinder())->find(
            $statements,
            fn (Node $node): bool => ($node instanceof ClosureNode || $node instanceof ArrowFunction)
                && $node->getStartLine() <= $reflection->getStartLine()
                && $node->getEndLine() === $reflection->getEndLine(),
        );
        $candidates = [];
        foreach ($nodes as $candidate) {
            if ($candidate instanceof ClosureNode || $candidate instanceof ArrowFunction) {
                $candidates[] = $candidate;
            }
        }

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

        return [
            $matches[0],
            self::sourceUsesStrictTypes($statements, $matches[0], $reflection),
        ];
    }

    /** @param array<array-key, Node> $statements */
    private static function sourceUsesStrictTypes(
        array $statements,
        ClosureNode|ArrowFunction $closure,
        ReflectionFunction $reflection,
    ): bool {
        $strictTypes = false;
        $declarations = (new NodeFinder())->findInstanceOf($statements, Declare_::class);

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

                throw ExportException::nonPortableClosure(
                    sprintf('declare(%s) cannot be preserved outside the closure expression', $name),
                    $reflection->getFileName() ?: null,
                    $declaration->getStartLine() ?: $reflection->getStartLine() ?: null,
                );
            }
        }

        return $strictTypes;
    }

    private static function declarationAffectsNode(Declare_ $declaration, Node $node): bool
    {
        if ($declaration->stmts !== null) {
            return self::nodeHasAncestor($node, $declaration);
        }

        $parent = $declaration->getAttribute('parent');
        if ($parent instanceof Node && !self::nodeHasAncestor($node, $parent)) {
            return false;
        }

        $declarationPosition = $declaration->getStartFilePos();
        $nodePosition = $node->getStartFilePos();

        return $declarationPosition < 0
            || $nodePosition < 0
            || $declarationPosition <= $nodePosition;
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
