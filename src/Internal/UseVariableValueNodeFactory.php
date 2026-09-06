<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use ReflectionReference;
use Throwable;
use UnitEnum;

/** @internal */
final class UseVariableValueNodeFactory
{
    /**
     * @param list<int|string> $path
     * @throws ExportException
     */
    public static function fromValue(
        mixed $value,
        string $variable,
        int $maxDepth,
        VarExport $varExport,
        int $depth = 0,
        array $path = [],
        ?string $filename = null,
        ?int $line = null,
    ): Node\Expr {
        self::assertSupported(
            $value,
            $variable,
            $maxDepth,
            $depth,
            $path,
            $filename,
            $line,
        );

        $expression = $varExport->export($value);
        try {
            $statements = (new ParserFactory())
                ->createForHostVersion()
                ->parse('<?php return ' . $expression . ';');
        } catch (Throwable $error) {
            throw new ExportException(
                sprintf('Exporter returned an invalid expression for captured variable $%s.', $variable),
                ['variable' => $variable],
                $filename,
                $line,
                $error,
            );
        }

        $return = $statements[0] ?? null;

        if (
            $statements === null
            || count($statements) !== 1
            || !$return instanceof Return_
            || !$return->expr instanceof Node\Expr
        ) {
            throw new ExportException(
                sprintf('Exporter returned an invalid expression for captured variable $%s.', $variable),
                ['variable' => $variable],
                $filename,
                $line,
            );
        }

        return $return->expr;
    }

    /**
     * @param list<int|string> $path
     * @throws ExportException
     */
    private static function assertSupported(
        mixed $value,
        string $variable,
        int $maxDepth,
        int $depth,
        array $path,
        ?string $filename,
        ?int $line,
    ): void {
        if ($depth > $maxDepth) {
            throw ExportException::captureDepthExceeded(
                $variable,
                $maxDepth,
                $depth,
                $path,
                $filename,
                $line,
            );
        }

        if (
            $value === null
            || is_bool($value)
            || is_int($value)
            || is_float($value)
            || is_string($value)
            || $value instanceof UnitEnum
        ) {
            return;
        }

        if (!is_array($value)) {
            throw ExportException::captureValueNotExportable(
                $variable,
                self::type($value),
                $path,
                $filename,
                $line,
            );
        }

        foreach ($value as $key => $item) {
            $itemPath = [...$path, $key];

            if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw ExportException::captureValueNotExportable(
                    $variable,
                    'array reference',
                    $itemPath,
                    $filename,
                    $line,
                );
            }

            self::assertSupported(
                $item,
                $variable,
                $maxDepth,
                $depth + 1,
                $itemPath,
                $filename,
                $line,
            );
        }
    }

    private static function type(mixed $value): string
    {
        if ($value instanceof \Closure) {
            return 'nested Closure';
        }

        if (is_object($value)) {
            return sprintf('object (%s)', $value::class);
        }

        if (is_resource($value)) {
            return sprintf('resource (%s)', get_resource_type($value));
        }

        return get_debug_type($value);
    }
}
