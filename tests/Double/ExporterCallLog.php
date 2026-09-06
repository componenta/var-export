<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

final class ExporterCallLog
{
    /** @var list<array{string, mixed}> */
    private array $calls = [];

    public function record(string $exporter, mixed $value): void
    {
        $this->calls[] = [$exporter, $value];
    }

    /** @return list<array{string, mixed}> */
    public function all(): array
    {
        return $this->calls;
    }
}
