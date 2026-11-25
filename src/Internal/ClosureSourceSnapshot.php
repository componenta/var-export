<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\NodeFinder;

/** @internal */
final readonly class ClosureSourceSnapshot
{
    /** @var array<int, list<ClosureNode|ArrowFunction>> */
    private array $byEndLine;

    /** @var list<Declare_> */
    private array $declarations;

    /** @param array<array-key, Node> $statements */
    public function __construct(private string $source, array $statements)
    {
        $finder = new NodeFinder();
        $nodes = $finder->find(
            $statements,
            static fn (Node $node): bool => $node instanceof ClosureNode || $node instanceof ArrowFunction,
        );
        $byEndLine = [];
        foreach ($nodes as $node) {
            if ($node instanceof ClosureNode || $node instanceof ArrowFunction) {
                $byEndLine[$node->getEndLine()][] = $node;
            }
        }

        $this->byEndLine = $byEndLine;
        $this->declarations = array_values($finder->findInstanceOf($statements, Declare_::class));
    }

    public function matchesSource(string $source): bool
    {
        return $this->source === $source;
    }

    /** @return list<ClosureNode|ArrowFunction> */
    public function candidatesAt(int $startLine, int $endLine): array
    {
        return array_values(array_filter(
            $this->byEndLine[$endLine] ?? [],
            static fn (ClosureNode|ArrowFunction $node): bool => $node->getStartLine() <= $startLine,
        ));
    }

    /** @return list<Declare_> */
    public function declarations(): array
    {
        return $this->declarations;
    }
}
