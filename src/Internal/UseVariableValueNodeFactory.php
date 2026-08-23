<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ClosureExportException;
use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;

/** @internal */
final class UseVariableValueNodeFactory
{
    private function __construct()
    {
    }

    /**
     * @param list<int|string> $path
     * @throws ClosureExportException
     */
    public static function fromValue(
        mixed $value,
        string $variable,
        int $maxDepth,
        int $depth = 0,
        array $path = [],
        ?string $filename = null,
        ?int $line = null,
    ): Node\Expr {
        if ($depth > $maxDepth) {
            throw ClosureExportException::captureDepthExceeded(
                $variable,
                $maxDepth,
                $depth,
                $path,
                $filename,
                $line,
            );
        }

        return match (true) {
            is_null($value) => new Node\Expr\ConstFetch(new FullyQualified('null')),
            is_bool($value) => new Node\Expr\ConstFetch(new FullyQualified($value ? 'true' : 'false')),
            is_int($value) => new Node\Scalar\Int_($value),
            is_float($value) => self::floatNode($value),
            is_string($value) => new Node\Scalar\String_($value),
            is_array($value) => self::arrayNode($value, $variable, $maxDepth, $depth, $path, $filename, $line),
            default => throw ClosureExportException::captureValueNotExportable(
                $variable,
                self::type($value),
                $path,
                $filename,
                $line,
            ),
        };
    }

    private static function floatNode(float $value): Node\Expr
    {
        if ($value === INF) {
            return new Node\Expr\ConstFetch(new FullyQualified('INF'));
        }

        if ($value === -INF) {
            return new Node\Expr\UnaryMinus(new Node\Expr\ConstFetch(new FullyQualified('INF')));
        }

        if (is_nan($value)) {
            return new Node\Expr\ConstFetch(new FullyQualified('NAN'));
        }

        return new Node\Scalar\Float_($value);
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $path
     */
    private static function arrayNode(
        array $array,
        string $variable,
        int $maxDepth,
        int $depth,
        array $path,
        ?string $filename,
        ?int $line,
    ): Node\Expr\Array_ {
        $items = [];
        $isList = array_is_list($array);

        foreach ($array as $key => $value) {
            $itemPath = [...$path, $key];

            if (\ReflectionReference::fromArrayElement($array, $key) !== null) {
                throw ClosureExportException::captureValueNotExportable(
                    $variable,
                    'array reference',
                    $itemPath,
                    $filename,
                    $line,
                );
            }

            $keyNode = $isList
                ? null
                : self::fromValue($key, $variable, $maxDepth, $depth + 1, $itemPath, $filename, $line);
            $valueNode = self::fromValue(
                $value,
                $variable,
                $maxDepth,
                $depth + 1,
                $itemPath,
                $filename,
                $line,
            );
            $items[] = new Node\ArrayItem($valueNode, $keyNode);
        }

        return new Node\Expr\Array_($items, ['kind' => Node\Expr\Array_::KIND_SHORT]);
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
