<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;

/**
 * Converts captured use() values into AST expression nodes.
 *
 * Only the value shapes validated by {@see ClosureValidator} can reach this
 * class; anything else is a bug upstream and triggers a LogicException.
 *
 * @internal This class is not part of the public API
 */
final class UseVariableValueNodeFactory
{
    private function __construct() {}

    public static function fromValue(mixed $value): Node\Expr
    {
        // Bool/null literals are emitted as fully-qualified `\true` / `\false`
        // / `\null` so that printing the closure body inside a different
        // namespace (e.g. a namespaced cache file) cannot make the unqualified
        // identifier resolve to a non-existent `Ns\true` constant.
        return match (true) {
            is_null($value) => new Node\Expr\ConstFetch(new Node\Name\FullyQualified('null')),
            is_bool($value) => new Node\Expr\ConstFetch(
                new Node\Name\FullyQualified($value ? 'true' : 'false'),
            ),
            is_int($value) => new Node\Scalar\Int_($value),
            is_float($value) => self::floatToNode($value),
            is_string($value) => new Node\Scalar\String_($value),
            is_array($value) => self::arrayToNode($value),
            $value instanceof \Closure => throw new \LogicException(
                'Nested closures cannot be inlined. This should have been caught by ClosureValidator.',
            ),
            default => throw new \LogicException(
                'Unexpected value type: ' . get_debug_type($value)
                . '. This should have been caught by ClosureValidator.',
            ),
        };
    }

    private static function floatToNode(float $value): Node\Expr
    {
        if (is_infinite($value)) {
            if ($value > 0) {
                return new Node\Expr\ConstFetch(new Node\Name('INF'));
            }
            return new Node\Expr\UnaryMinus(
                new Node\Expr\ConstFetch(new Node\Name('INF')),
            );
        }

        if (is_nan($value)) {
            return new Node\Expr\ConstFetch(new Node\Name('NAN'));
        }

        return new Node\Scalar\Float_($value);
    }

    private static function arrayToNode(array $array): Node\Expr\Array_
    {
        $items = [];
        $isSequential = array_is_list($array);

        foreach ($array as $key => $value) {
            $keyNode = $isSequential ? null : self::fromValue($key);
            $items[] = new Node\ArrayItem(self::fromValue($value), $keyNode);
        }

        return new Node\Expr\Array_($items, ['kind' => Node\Expr\Array_::KIND_SHORT]);
    }
}
