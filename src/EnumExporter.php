<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use UnitEnum;

final readonly class EnumExporter implements VarExportInterface
{
    public function export(mixed $var): ?string
    {
        if (!$var instanceof UnitEnum) {
            return null;
        }

        return '\\' . ltrim($var::class, '\\') . '::' . $var->name;
    }
}
