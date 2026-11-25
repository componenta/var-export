<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Closure;

/** @internal */
final readonly class ExportContext
{
    /**
     * @param Closure(mixed, int, list<int|string>): string $dispatch
     * @param list<int|string> $path
     */
    public function __construct(
        private Closure $dispatch,
        public int $depth = 0,
        public array $path = [],
    ) {
    }

    public function exportChild(mixed $value, int|string $key): string
    {
        return ($this->dispatch)($value, $this->depth + 1, [...$this->path, $key]);
    }
}
