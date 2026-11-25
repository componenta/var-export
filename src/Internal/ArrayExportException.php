<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ExportException;

/** @internal */
final class ArrayExportException extends ExportException
{
    /** @param list<int|string> $path */
    public function __construct(
        string $message,
        private readonly array $path,
        private readonly string $reason,
        private readonly ?int $maxDepth = null,
    ) {
        parent::__construct($message, ['path' => $path] + ($maxDepth === null ? [] : ['max_depth' => $maxDepth]));
    }

    /** @param list<int|string> $path */
    public static function depth(int $maxDepth, array $path = []): self
    {
        return new self(
            sprintf('Cannot export an array nested deeper than %d levels.', $maxDepth),
            $path,
            'depth',
            $maxDepth,
        );
    }

    public function withPrependedKey(int|string $key): ExportException
    {
        $path = [$key, ...$this->path];

        return match ($this->reason) {
            'reference' => ExportException::arrayReference($path),
            'append_index' => ExportException::arrayAppendIndex($path),
            'pointer' => ExportException::arrayPointer($path),
            'depth' => self::depth($this->maxDepth ?? throw new \LogicException('Missing array depth limit.'), $path),
            default => throw new \LogicException('Unknown array export failure.'),
        };
    }
}
