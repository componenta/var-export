<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use SplObjectStorage;

/** @internal */
final readonly class ExportContext
{
    /** @var list<int|string> */
    public array $path;

    /** @var SplObjectStorage<object, null> */
    public SplObjectStorage $activeObjects;

    /**
     * @param list<int|string> $path
     * @param SplObjectStorage<object, null>|null $activeObjects
     */
    public function __construct(
        public int $depth = 0,
        array $path = [],
        public string $baseIndent = '',
        ?SplObjectStorage $activeObjects = null,
    ) {
        $this->path = $path;
        $this->activeObjects = $activeObjects ?? new SplObjectStorage();
    }

    public static function root(): self
    {
        return new self();
    }

    public function child(int|string $segment, string $baseIndent): self
    {
        return new self(
            $this->depth + 1,
            [...$this->path, $segment],
            $baseIndent,
            $this->activeObjects,
        );
    }

    public function location(): string
    {
        if ($this->path === []) {
            return 'root';
        }

        $result = '$value';
        foreach ($this->path as $segment) {
            $result .= is_int($segment)
                ? sprintf('[%d]', $segment)
                : sprintf('[%s]', var_export($segment, true));
        }

        return $result;
    }
}
