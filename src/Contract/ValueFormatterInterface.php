<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

interface ValueFormatterInterface
{
    public function formatNumeric(int|float $value): string;

    public function escapeString(string $value): string;

    public function formatBool(bool $value): string;

    public function formatNull(): string;
}
