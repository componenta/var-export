<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

/** @internal */
final class WarningGuard
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        set_error_handler(
            static fn(int $_severity, string $_message, string $_file, int $_line): bool => true,
            E_WARNING,
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function __construct() {}
}
