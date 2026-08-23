<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

final class ConfigurationException extends ExportException
{
    public static function invalidIndent(string $indent): self
    {
        return new self(
            sprintf(
                'Invalid indent %s. Use one or more spaces, or exactly one tab.',
                var_export($indent, true),
            ),
            ['indent' => $indent, 'length' => strlen($indent)],
        );
    }

    public static function invalidMaxDepth(int $maxDepth): self
    {
        return new self(
            sprintf('Invalid maxDepth %d; expected an integer greater than or equal to 1.', $maxDepth),
            ['max_depth' => $maxDepth],
        );
    }

    public static function invalidCacheLimit(string $name, int $value): self
    {
        return new self(
            sprintf('Invalid closure source cache limit %s=%d; expected an integer greater than or equal to 1.', $name, $value),
            ['option' => $name, 'value' => $value],
        );
    }
}
