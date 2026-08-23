<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Contract\ValueFormatterInterface;

/** @internal */
final readonly class ValueFormatter implements ValueFormatterInterface
{
    public function formatNumeric(int|float $value): string
    {
        return var_export($value, true);
    }

    public function escapeString(string $value): string
    {
        return var_export($value, true);
    }

    public function formatBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public function formatNull(): string
    {
        return 'null';
    }
}
