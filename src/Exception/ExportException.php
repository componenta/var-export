<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

use Exception;
use Componenta\VarExport\Contract\ExceptionInterface;

/**
 * Base exception for export failures.
 *
 * Provides detailed context about what failed and where, including:
 * - Variable type and class name for objects
 * - File and line number when available (set as exception's own file/line)
 * - Array key path for nested structures
 *
 * When file/line of the problematic variable are known, they are set
 * as the exception's own $file and $line properties, making them visible
 * in standard exception rendering and stack traces.
 *
 * Note: The $context property stores metadata about the variable,
 * NOT the actual value, to prevent sensitive data leaks in logs.
 */
class ExportException extends Exception implements ExceptionInterface
{
    /**
     * @param string $message Error description
     * @param array<string, mixed> $context Metadata about the failed export
     * @param string|null $sourceFile File where the problematic variable is defined
     * @param int|null $sourceLine Line where the problematic variable is defined
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?string $sourceFile = null,
        ?int $sourceLine = null,
    ) {
        parent::__construct($message);

        // Override exception's file/line to point to the source of the problem
        if ($sourceFile !== null) {
            $this->file = $sourceFile;
        }

        if ($sourceLine !== null) {
            $this->line = $sourceLine;
        }
    }

    /**
     * Create exception for unsupported type.
     */
    public static function unsupportedType(mixed $value): self
    {
        $type = get_debug_type($value);

        return new self(
            "Cannot export value of type '{$type}'",
            ['type' => $type],
        );
    }

    /**
     * Create exception for object that cannot be exported.
     */
    public static function unexportableObject(object $object): self
    {
        $class = $object::class;

        return new self(
            "Object of type '{$class}' cannot be exported. " .
            "Only closures are supported as object values.",
            ['class' => $class],
        );
    }

    /**
     * Create exception for resource.
     */
    public static function resourceNotExportable(mixed $resource): self
    {
        // In PHP 8+, many former resources are now opaque objects.
        if (is_object($resource)) {
            $type = $resource::class;
            return new self(
                "Resource object of type '{$type}' cannot be exported",
                ['resource_type' => $type, 'is_object' => true],
            );
        }

        // get_resource_type() returns 'Unknown' for closed resources.
        $type = is_resource($resource) ? get_resource_type($resource) : 'closed resource';

        return new self(
            "Resource of type '{$type}' cannot be exported. " .
            "Resources cannot be serialized to PHP code.",
            ['resource_type' => $type],
        );
    }

    /**
     * Format a key path for error messages.
     *
     * @param array<int|string> $keyPath
     */
    public static function formatKeyPath(array $keyPath): string
    {
        if (empty($keyPath)) {
            return 'root';
        }

        $parts = [];
        foreach ($keyPath as $key) {
            if (is_int($key)) {
                $parts[] = "[{$key}]";
            } else {
                $escaped = addcslashes($key, "'\\");
                $parts[] = "['{$escaped}']";
            }
        }

        return '$array' . implode('', $parts);
    }
}
