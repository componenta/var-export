<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ValueFormatter;
use UnitEnum;

final readonly class VarExporter
{
    private ValueFormatterInterface $valueFormatter;
    private ArrayExporter $arrayExporter;
    private ClosureExporter $closureExporter;
    private ObjectExporterInterface $objectExporter;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
        $this->closureExporter = new ClosureExporter($config);

        $dispatch = fn(mixed $value, ExportContext $context): string => $this->exportValue($value, $context);

        $objectExporter ??= new ObjectExporter(
            $config,
            $this->valueFormatter,
            closureExporter: $this->closureExporter,
        );
        if ($objectExporter instanceof ObjectExporter) {
            $objectExporter = $objectExporter->withValueExporter($dispatch);
        }

        $this->objectExporter = $objectExporter;
        $this->arrayExporter = new ArrayExporter(
            $config,
            $this->closureExporter,
            $this->objectExporter,
            $this->valueFormatter,
            $dispatch,
        );
    }

    public function export(mixed $var): string
    {
        return $this->exportValue($var, ExportContext::root());
    }

    private function exportValue(mixed $value, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw new ExportException(
                sprintf(
                    'Maximum nesting depth of %d exceeded at %s.',
                    $this->config->maxDepth,
                    $context->location(),
                ),
                [
                    'max_depth' => $this->config->maxDepth,
                    'depth' => $context->depth,
                    'path' => $context->path,
                ],
            );
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value), is_string($value) => $this->valueFormatter->format($value),
            is_array($value) => $this->arrayExporter->exportWithContext($value, $context),
            $value instanceof Closure => $this->closureExporter->exportWithContext($value, $context),
            $value instanceof UnitEnum => '\\' . $value::class . '::' . $value->name,
            $value instanceof ObjectExporterInterface => $this->objectExporter->export($value),
            is_object($value) => $this->objectExporter instanceof ObjectExporter
                ? $this->objectExporter->exportWithContext($value, $context)
                : $this->objectExporter->export($value),
            is_resource($value) => throw ExportException::resourceNotExportable($value),
            default => throw ExportException::unsupportedType($value),
        };
    }
}
