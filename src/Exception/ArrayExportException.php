<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

/**
 * Exception thrown when array export fails.
 *
 * Provides detailed context including:
 * - Full key path to the problematic element
 * - Type of the unexportable value
 * - Current nesting depth
 */
class ArrayExportException extends ExportException
{
    /**
     * Create exception for maximum depth exceeded.
     *
     * @param array<int|string> $keyPath Path to where depth was exceeded
     */
    public static function maxDepthExceeded(
        int $maxDepth,
        int $currentDepth,
        array $keyPath = [],
    ): self {
        $location = self::formatKeyPath($keyPath);

        return new self(
            "Maximum nesting depth of {$maxDepth} exceeded at depth {$currentDepth}. " .
            "Location: {$location}. " .
            "Consider increasing maxDepth in ExportConfig or flattening your data structure.",
            [
                'max_depth' => $maxDepth,
                'current_depth' => $currentDepth,
                'key_path' => $keyPath,
            ],
        );
    }

    /**
     * Create exception for unexportable array element.
     *
     * @param int|string $key The array key where the error occurred
     * @param string $type Type of the unexportable value
     * @param int $depth Current nesting depth
     * @param array<int|string> $keyPath Full path to the element
     */
    public static function unexportableElement(
        int|string $key,
        string $type,
        int $depth,
        array $keyPath = [],
    ): self {
        $location = self::formatKeyPath($keyPath);

        $hint = match ($type) {
            'resource', 'resource (closed)' =>
                'Resources cannot be serialized. Consider removing or replacing with a serializable value.',
            'object' =>
                'Only Closure objects can be exported. Consider implementing __serialize/__unserialize or converting to array.',
            default => str_starts_with($type, 'resource') ?
                'Resources cannot be serialized.' :
                "Type '{$type}' is not exportable.",
        };

        return new self(
            "Cannot export array element at {$location} (type: {$type}, depth: {$depth}). {$hint}",
            [
                'key' => $key,
                'type' => $type,
                'depth' => $depth,
                'key_path' => $keyPath,
            ],
        );
    }

}
