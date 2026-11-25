<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Exception\UnsupportedValueException;

final readonly class VarExport
{
    /** @var list<VarExportInterface> */
    private array $exporters;

    public function __construct(VarExportInterface ...$exporters)
    {
        $boundExporters = [];

        foreach ($exporters as $exporter) {
            $boundExporters[] = $exporter instanceof VarExportAwareInterface
                ? $exporter->withVarExport($this)
                : $exporter;
        }

        $this->exporters = $boundExporters;
    }

    public static function withDefaults(): self
    {
        return new self(
            new ScalarExporter(),
            new ArrayExporter(),
            new EnumExporter(),
            new ClosureExporter(),
        );
    }

    public function export(mixed $var): string
    {
        if (!isset($this->exporters)) {
            throw new ExportException('Cannot export values while exporters are being bound.');
        }

        foreach ($this->exporters as $exporter) {
            $result = $exporter->export($var);

            if ($result !== null) {
                return $result;
            }
        }

        throw UnsupportedValueException::forValue($var);
    }
}
