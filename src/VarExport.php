<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Exception\UnsupportedValueException;
use Componenta\VarExport\Internal\ContextualExporterInterface;
use Componenta\VarExport\Internal\ExportContext;
use Componenta\VarExport\Internal\ExpressionIndentation;

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

        return $this->dispatch($var);
    }

    /** @param list<int|string> $path */
    private function dispatch(mixed $var, int $depth = 0, array $path = []): string
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter instanceof ContextualExporterInterface) {
                $result = $exporter->exportInContext($var, new ExportContext($this->dispatch(...), $depth, $path));
                if ($result !== null) {
                    return $result;
                }

                continue;
            }

            $result = $exporter->export($var);

            if ($result !== null) {
                return $depth === 0 || !str_contains($result, "\n")
                    ? $result
                    : ExpressionIndentation::atDepth($result, $depth);
            }
        }

        throw UnsupportedValueException::forValue($var);
    }
}
