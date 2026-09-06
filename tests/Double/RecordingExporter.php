<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

use Componenta\VarExport\VarExportInterface;

final readonly class RecordingExporter implements VarExportInterface
{
    public function __construct(
        private ExporterCallLog $calls,
        private string $name,
        private ?string $result,
    ) {
    }

    public function export(mixed $var): ?string
    {
        $this->calls->record($this->name, $var);

        return $this->result;
    }
}
