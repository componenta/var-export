<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

use Componenta\VarExport\Internal\ArrayExportException;
use Exception;
use Throwable;

class ExportException extends Exception implements ExceptionInterface
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?string $sourceFile = null,
        ?int $sourceLine = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);

        if ($sourceFile !== null) {
            $this->file = $sourceFile;
        }

        if ($sourceLine !== null) {
            $this->line = $sourceLine;
        }
    }

    public static function exporterIsNotBound(string $exporter): self
    {
        return new self(
            sprintf('%s must be bound with withVarExport() before exporting a supported value.', $exporter),
            ['exporter' => $exporter],
        );
    }

    /** @param list<int|string> $path */
    public static function arrayReference(array $path): self
    {
        return new ArrayExportException(
            sprintf('Cannot export array reference at %s.', self::formatPath('$array', $path)),
            $path,
            'reference',
        );
    }

    /** @param list<int|string> $path */
    public static function arrayAppendIndex(array $path): self
    {
        return new ArrayExportException(
            sprintf('Cannot preserve the next append index at %s in an array literal.', self::formatPath('$array', $path)),
            $path,
            'append_index',
        );
    }

    /** @param list<int|string> $path */
    public static function arrayPointer(array $path): self
    {
        return new ArrayExportException(
            sprintf('Cannot preserve the array pointer at %s in an array literal.', self::formatPath('$array', $path)),
            $path,
            'pointer',
        );
    }

    public static function nonPortableClosure(
        string $reason,
        ?string $filename = null,
        ?int $line = null,
    ): self {
        return new self(
            sprintf('Closure is not portable as a standalone PHP expression: %s.', $reason),
            ['reason' => $reason],
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
                self::formatPath('$capture', $path),
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
                'Captured variable $%s exceeds maximum depth %d at depth %d (%s).',
                $variable,
                $maxDepth,
                $depth,
                self::formatPath('$capture', $path),
            ),
            ['variable' => $variable, 'max_depth' => $maxDepth, 'depth' => $depth, 'path' => $path],
            $filename,
            $line,
        );
    }

    /** @param list<int|string> $path */
    private static function formatPath(string $root, array $path): string
    {
        foreach ($path as $segment) {
            $root .= is_int($segment)
                ? sprintf('[%d]', $segment)
                : sprintf('[%s]', var_export($segment, true));
        }

        return $root;
    }
}
