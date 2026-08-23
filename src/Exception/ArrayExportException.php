<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

use Throwable;

final class ArrayExportException extends ExportException
{
    /** @param array<int|string> $keyPath */
    public static function maxDepthExceeded(int $maxDepth, int $currentDepth, array $keyPath = []): self
    {
        return new self(
            sprintf(
                'Maximum nesting depth of %d exceeded at depth %d. Location: %s.',
                $maxDepth,
                $currentDepth,
                self::formatKeyPath($keyPath),
            ),
            ['max_depth' => $maxDepth, 'current_depth' => $currentDepth, 'key_path' => $keyPath],
        );
    }

    /** @param array<int|string> $keyPath */
    public static function unexportableElement(
        int|string $key,
        string $type,
        int $depth,
        array $keyPath = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Cannot export array element at %s (type: %s, depth: %d).',
                self::formatKeyPath($keyPath),
                $type,
                $depth,
            ),
            ['key' => $key, 'type' => $type, 'depth' => $depth, 'key_path' => $keyPath],
            previous: $previous,
        );
    }

    /** @param array<int|string> $keyPath */
    public static function referencedElement(int|string $key, array $keyPath): self
    {
        return new self(
            sprintf(
                'Cannot export array reference at %s without changing reference semantics.',
                self::formatKeyPath($keyPath),
            ),
            ['key' => $key, 'key_path' => $keyPath],
        );
    }

    /** @param array<int|string> $keyPath */
    public static function closureExporterMissing(int|string $key, int $depth, array $keyPath): self
    {
        return new self(
            sprintf(
                'Cannot export Closure at %s: no ClosureExporterInterface is configured.',
                self::formatKeyPath($keyPath),
            ),
            ['key' => $key, 'depth' => $depth, 'key_path' => $keyPath],
        );
    }

    public static function invalidDepth(int $depth): self
    {
        return new self(
            sprintf('Depth must be non-negative; got %d.', $depth),
            ['depth' => $depth],
        );
    }
}
