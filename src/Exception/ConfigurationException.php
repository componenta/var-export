<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

/**
 * Exception thrown when configuration is invalid.
 */
class ConfigurationException extends ExportException
{
    /**
     * Create exception for invalid indent string.
     */
    public static function invalidIndent(string $indent): self
    {
        $escaped = addcslashes($indent, "\0..\37\\");
        $length = strlen($indent);

        $hint = match (true) {
            $indent === '' => 'Indent cannot be empty.',
            trim($indent) !== '' => 'Indent must contain only whitespace characters (spaces or tabs).',
            default => 'Invalid indent value.',
        };

        return new self(
            "Invalid indent string (length: {$length}, value: \"{$escaped}\"): {$hint} " .
            "Use spaces (e.g., '    ') or tabs (e.g., \"\\t\").",
            ['indent' => $indent, 'length' => $length],
        );
    }

    /**
     * Create exception for invalid max depth.
     */
    public static function invalidMaxDepth(int $maxDepth): self
    {
        return new self(
            "Invalid maxDepth value: {$maxDepth}. " .
            "maxDepth must be a positive integer (>= 1). " .
            "Recommended range: 16-128 for most use cases.",
            ['max_depth' => $maxDepth],
        );
    }
}
