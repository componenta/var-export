<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

use ReflectionFunction;

/**
 * Exception thrown when closure export fails.
 *
 * Provides detailed context including:
 * - Source file and line number (also set as exception's own file/line for rendering)
 * - Closure signature (parameters)
 * - Specific reason for failure
 * - Variable names that caused issues
 *
 * The exception's $file and $line properties point to the problematic closure's
 * location, making it immediately visible in stack traces and error renderers.
 */
class ClosureExportException extends ExportException
{
    /**
     * Create exception when source file cannot be located.
     */
    public static function sourceNotFound(string $filename): self
    {
        return new self(
            "Cannot locate closure source file: '{$filename}'. " .
            "The closure may have been defined in eval()'d code or a stream wrapper.",
            ['filename' => $filename],
            $filename !== 'unknown' ? $filename : null,
        );
    }

    /**
     * Create exception when source file cannot be read.
     */
    public static function sourceUnreadable(string $filename): self
    {
        $reason = match (true) {
            !file_exists($filename) => 'File does not exist',
            !is_readable($filename) => 'File is not readable (permission denied)',
            default => 'Unknown read error',
        };

        return new self(
            "Cannot read closure source file: '{$filename}'. {$reason}.",
            ['filename' => $filename, 'reason' => $reason],
            $filename,
        );
    }

    /**
     * Create exception when closure node cannot be found in AST.
     */
    public static function nodeNotFound(int $line, string $filename): self
    {
        return new self(
            "Cannot locate closure AST node at line {$line} in '{$filename}'. " .
            "The source file may have been modified after the closure was defined, " .
            "or the closure was created dynamically.",
            ['line' => $line, 'filename' => $filename],
            $filename,
            $line,
        );
    }

    /**
     * Create exception when parsing fails.
     */
    public static function parsingFailed(string $filename, string $reason): self
    {
        return new self(
            "Failed to parse PHP source file '{$filename}': {$reason}. " .
            "Ensure the file contains valid PHP syntax.",
            ['filename' => $filename, 'reason' => $reason],
            $filename,
        );
    }

    /**
     * Create exception when closure has bound $this.
     */
    public static function boundThis(ReflectionFunction $reflector): self
    {
        $file = $reflector->getFileName() ?: null;
        $line = $reflector->getStartLine() ?: null;
        $boundClass = get_class($reflector->getClosureThis());
        $signature = self::formatClosureSignature($reflector);

        $fileDisplay = $file ?? 'unknown';
        $lineDisplay = $line ?? 0;

        return new self(
            "Closure bound to \$this cannot be exported. " .
            "Location: {$fileDisplay}:{$lineDisplay}. " .
            "Signature: {$signature}. " .
            "Bound to instance of: {$boundClass}. " .
            "Solution: Use 'static function()' or call \$closure->bindTo(null) before export.",
            [
                'file' => $file,
                'line' => $line,
                'bound_class' => $boundClass,
                'signature' => $signature,
            ],
            $file,
            $line,
        );
    }

    /**
     * Create exception when closure use variables cannot be inlined.
     *
     * @param array<string, string> $variables Variable names mapped to their types
     * @param string|null $filename Source file
     * @param int|null $line Source line
     */
    public static function cannotInlineUseVariables(
        array $variables,
        ?string $filename = null,
        ?int $line = null,
    ): self {
        $details = [];
        foreach ($variables as $name => $type) {
            $details[] = "\${$name} ({$type})";
        }
        $variableList = implode(', ', $details);

        $location = $filename !== null
            ? " at {$filename}" . ($line !== null ? ":{$line}" : '')
            : '';

        return new self(
            "Cannot inline use() variables{$location}: {$variableList}. " .
            "Only scalar values and arrays of scalars can be inlined. Nested closures are not supported. " .
            "Solution: Use ClosureUseMode::Preserve or ensure all captured values are exportable.",
            [
                'variables' => $variables,
                'filename' => $filename,
                'line' => $line,
            ],
            $filename,
            $line,
        );
    }

    /**
     * Create exception when multiple closures are on the same line.
     */
    public static function ambiguousLocation(int $line, int $count, string $filename): self
    {
        return new self(
            "Found {$count} closures on line {$line} in '{$filename}'. " .
            "Cannot determine which one to export. " .
            "Solution: Place each closure on a separate line.",
            ['line' => $line, 'count' => $count, 'filename' => $filename],
            $filename,
            $line,
        );
    }

    /**
     * Format closure signature for error messages.
     */
    private static function formatClosureSignature(ReflectionFunction $reflector): string
    {
        $params = [];
        foreach ($reflector->getParameters() as $param) {
            $paramStr = '';

            if ($param->hasType()) {
                $paramStr .= $param->getType() . ' ';
            }

            if ($param->isPassedByReference()) {
                $paramStr .= '&';
            }

            if ($param->isVariadic()) {
                $paramStr .= '...';
            }

            $paramStr .= '$' . $param->getName();

            if ($param->isDefaultValueAvailable()) {
                $paramStr .= ' = ...';
            }

            $params[] = $paramStr;
        }

        $returnType = $reflector->hasReturnType()
            ? ': ' . $reflector->getReturnType()
            : '';

        return 'function(' . implode(', ', $params) . ')' . $returnType;
    }
}
