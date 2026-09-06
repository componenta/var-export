<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

use Componenta\VarExport\VarExport;
use Componenta\VarExport\VarExportAwareInterface;
use Componenta\VarExport\VarExportInterface;

final class AwareExporterSpy implements VarExportInterface, VarExportAwareInterface
{
    private ?VarExport $varExport = null;

    public function __construct(private readonly ?AwarenessState $state = null)
    {
    }

    public function withVarExport(VarExport $varExport): static
    {
        $exporter = clone $this;
        $exporter->varExport = $varExport;

        return $exporter;
    }

    public function export(mixed $var): ?string
    {
        if ($this->varExport === null) {
            return null;
        }

        if ($this->state !== null) {
            $this->state->boundTo = $this->varExport;
            $this->state->exportedBy = $this;
        }

        return (string) spl_object_id($this->varExport);
    }

    public function isBound(): bool
    {
        return $this->varExport !== null;
    }
}
