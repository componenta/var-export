<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

/** @internal */
final class ArrayKeyOrder
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $array
     * @return list<int|string>
     */
    public static function orderedKeys(array $array, bool $sortKeys): array
    {
        /** @var list<int|string> $keys */
        $keys = array_keys($array);
        if (!$sortKeys) {
            return $keys;
        }

        usort($keys, static function (int|string $left, int|string $right): int {
            if (is_int($left) && is_string($right)) {
                return -1;
            }
            if (is_string($left) && is_int($right)) {
                return 1;
            }
            if (is_int($left)) {
                /** @var int $right */
                return $left <=> $right;
            }

            /** @var string $right */
            return strcmp($left, $right);
        });

        return $keys;
    }
}
