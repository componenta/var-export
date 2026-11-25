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
        $this->assertInitialized();

        return $this->dispatch($var);
    }

    /**
     * Start a direct composite export while using this chain for nested values.
     * The explicitly selected exporter is also the fallback for nested values
     * that no configured exporter accepts.
     *
     * @internal
     */
    public function exportUsing(ContextualExporterInterface $exporter, mixed $var): string
    {
        $this->assertInitialized();

        return $exporter->exportInContext($var, $this->context(0, [], $exporter))
            ?? throw UnsupportedValueException::forValue($var);
    }

    private function assertInitialized(): void
    {
        if (!isset($this->exporters)) {
            throw new ExportException('Cannot export values while exporters are being bound.');
        }
    }

    /** @param list<int|string> $path */
    private function dispatch(
        mixed $var,
        int $depth = 0,
        array $path = [],
        ?ContextualExporterInterface $fallback = null,
    ): string {
        foreach ($this->exporters as $exporter) {
            if ($exporter instanceof ContextualExporterInterface) {
                $result = $exporter->exportInContext($var, $this->context($depth, $path, $fallback));
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

        if ($fallback !== null) {
            $result = $fallback->exportInContext($var, $this->context($depth, $path, $fallback));
            if ($result !== null) {
                return $result;
            }
        }

        throw UnsupportedValueException::forValue($var);
    }

    /** @param list<int|string> $path */
    private function context(int $depth, array $path, ?ContextualExporterInterface $fallback): ExportContext
    {
        if ($fallback === null) {
            return new ExportContext($this->dispatch(...), $depth, $path);
        }

        return new ExportContext(
            fn (mixed $var, int $depth, array $path): string => $this->dispatch($var, $depth, $path, $fallback),
            $depth,
            $path,
        );
    }
}
