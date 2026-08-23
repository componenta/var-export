<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

use ReflectionFunction;
use Throwable;

final class ClosureExportException extends ExportException
{
    public static function sourceNotFound(string $filename): self
    {
        return new self(
            sprintf('Cannot locate closure source file "%s".', $filename),
            ['filename' => $filename],
            $filename !== 'unknown' ? $filename : null,
        );
    }

    public static function sourceUnreadable(string $filename): self
    {
        return new self(
            sprintf('Cannot read closure source file "%s".', $filename),
            ['filename' => $filename],
            $filename,
        );
    }

    public static function sourceTooLarge(string $filename, int $bytes, int $limit): self
    {
        return new self(
            sprintf(
                'Closure source file "%s" is %d bytes; configured source limit is %d bytes.',
                $filename,
                $bytes,
                $limit,
            ),
            ['filename' => $filename, 'bytes' => $bytes, 'limit' => $limit],
            $filename,
        );
    }

    public static function parsingFailed(string $filename, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to parse closure source file "%s": %s.', $filename, $reason),
            ['filename' => $filename, 'reason' => $reason],
            $filename,
            previous: $previous,
        );
    }

    public static function nodeNotFound(int $line, string $filename): self
    {
        return new self(
            sprintf('Cannot locate closure AST node at %s:%d.', $filename, $line),
            ['line' => $line, 'filename' => $filename],
            $filename,
            $line,
        );
    }

    public static function staleSource(ReflectionFunction $reflection): self
    {
        $file = $reflection->getFileName() ?: null;
        $line = $reflection->getStartLine() ?: null;

        return new self(
            'Closure source no longer matches the runtime Reflection metadata; rebuild from unchanged source.',
            ['filename' => $file, 'line' => $line, 'closure' => $reflection->getName()],
            $file,
            $line,
        );
    }

    public static function ambiguousLocation(int $line, int $count, string $filename): self
    {
        return new self(
            sprintf('Found %d indistinguishable closure candidates at %s:%d.', $count, $filename, $line),
            ['line' => $line, 'count' => $count, 'filename' => $filename],
            $filename,
            $line,
        );
    }

    public static function boundThis(ReflectionFunction $reflection): self
    {
        $file = $reflection->getFileName() ?: null;
        $line = $reflection->getStartLine() ?: null;
        $bound = $reflection->getClosureThis();

        return new self(
            sprintf(
                'Closure bound to $this cannot be exported safely%s.',
                $bound !== null ? sprintf(' (bound class: %s)', $bound::class) : '',
            ),
            ['bound_class' => $bound !== null ? $bound::class : null],
            $file,
            $line,
        );
    }

    public static function nonPortableScope(ReflectionFunction $reflection, string $reason): self
    {
        $file = $reflection->getFileName() ?: null;
        $line = $reflection->getStartLine() ?: null;

        return new self(
            sprintf('Closure class scope cannot be reproduced safely: %s.', $reason),
            ['closure' => $reflection->getName(), 'reason' => $reason],
            $file,
            $line,
        );
    }

    /**
     * @param array<string, string> $variables
     */
    public static function cannotInlineUseVariables(
        array $variables,
        ?string $filename = null,
        ?int $line = null,
    ): self {
        $parts = [];
        foreach ($variables as $name => $type) {
            $parts[] = sprintf('$%s (%s)', $name, $type);
        }

        return new self(
            sprintf('Cannot freeze closure captures: %s.', implode(', ', $parts)),
            ['variables' => $variables, 'filename' => $filename, 'line' => $line],
            $filename,
            $line,
        );
    }

    /** @param list<int|string> $path */
    public static function captureValueNotExportable(
        string $variable,
        string $type,
        array $path,
        ?string $filename = null,
        ?int $line = null,
    ): self {
        return new self(
            sprintf(
                'Cannot freeze captured variable $%s at %s: unsupported %s.',
                $variable,
                self::formatCapturePath($path),
                $type,
            ),
            ['variable' => $variable, 'type' => $type, 'path' => $path],
            $filename,
            $line,
        );
    }

    /** @param list<int|string> $path */
    public static function captureDepthExceeded(
        string $variable,
        int $maxDepth,
        int $depth,
        array $path,
        ?string $filename = null,
        ?int $line = null,
    ): self {
        return new self(
            sprintf(
                'Captured variable $%s exceeds maxDepth %d at depth %d (%s).',
                $variable,
                $maxDepth,
                $depth,
                self::formatCapturePath($path),
            ),
            ['variable' => $variable, 'max_depth' => $maxDepth, 'depth' => $depth, 'path' => $path],
            $filename,
            $line,
        );
    }

    public static function internalFailure(ReflectionFunction $reflection, Throwable $previous): self
    {
        $file = $reflection->getFileName() ?: null;
        $line = $reflection->getStartLine() ?: null;

        return new self(
            sprintf('Closure export failed: %s.', $previous->getMessage()),
            ['closure' => $reflection->getName(), 'exception' => $previous::class],
            $file,
            $line,
            $previous,
        );
    }

    /** @param list<int|string> $path */
    private static function formatCapturePath(array $path): string
    {
        if ($path === []) {
            return 'capture root';
        }

        $parts = [];
        foreach ($path as $segment) {
            $parts[] = is_int($segment)
                ? sprintf('[%d]', $segment)
                : sprintf('[%s]', var_export($segment, true));
        }

        return '$capture' . implode('', $parts);
    }
}
