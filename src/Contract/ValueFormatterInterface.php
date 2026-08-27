<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

interface ValueFormatterInterface
{
    public function format(null|bool|int|float|string $value): string;
}
