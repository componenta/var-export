<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ExportException;

/** @internal */
final class ArrayExportException extends ExportException
{
    /** @param list<int|string> $path */
    private function __construct(
        string $message,
        private readonly array $path,
        private readonly string $reason,
        private readonly ?int $maxDepth = null,
    ) {
        parent::__construct($message, ['path' => $path] + ($maxDepth === null ? [] : ['max_depth' => $maxDepth]));
    }

    /** @param list<int|string> $path */
    public static function reference(array $path): self
    {
        return new self(ExportException::arrayReference($path)->getMessage(), $path, 'reference');
    }

    /** @param list<int|string> $path */
    public static function appendIndex(array $path): self
    {
        return new self(ExportException::arrayAppendIndex($path)->getMessage(), $path, 'append_index');
    }

    /** @param list<int|string> $path */
    public static function pointer(array $path): self
    {
        return new self(ExportException::arrayPointer($path)->getMessage(), $path, 'pointer');
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

    /** @param list<int|string> $prefix */
    public function withPrependedPath(array $prefix): self
    {
        $path = [...$prefix, ...$this->path];

        return match ($this->reason) {
            'reference' => self::reference($path),
            'append_index' => self::appendIndex($path),
            'pointer' => self::pointer($path),
            'depth' => self::depth($this->maxDepth ?? throw new \LogicException('Missing array depth limit.'), $path),
            default => throw new \LogicException('Unknown array export failure.'),
        };
    }
}
