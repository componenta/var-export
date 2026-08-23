<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Componenta\VarExport\Source\ClosureSourceCandidate;

/**
 * Cache/index abstraction used by closure source extraction.
 */
interface ClosureSourceCacheInterface
{
    /**
     * Return detached candidates that start on the requested source line.
     * Mutating returned AST nodes must never mutate cached state.
     *
     * @return list<ClosureSourceCandidate>
     * @throws ExceptionInterface
     */
    public function candidates(string $filename, int $startLine): array;

    public function clear(): void;

    public function size(): int;
}
