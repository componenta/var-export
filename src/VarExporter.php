<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Contract\ContextualClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Contract\VarExporterInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ValueFormatter;
use Componenta\VarExport\Source\ClosureSourceCache;

final readonly class VarExporter implements VarExporterInterface, ContextualValueExporterInterface
{
    private ValueFormatterInterface $valueFormatter;
    private ArrayExporterInterface $arrayExporter;
    private ClosureExporterInterface $closureExporter;
    private ContextualObjectExporterInterface $objectExporter;
    private ClosureSourceCacheInterface $sourceCache;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ClosureSourceCacheInterface $sourceCache = null,
        ?ContextualObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
        ?ClosureExporterInterface $closureExporter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
        $this->sourceCache = $sourceCache ?? new ClosureSourceCache();
        $this->closureExporter = $closureExporter ?? new ClosureExporter($config, $this->sourceCache);

        $objectExporter ??= new ObjectExporter(
            $config,
            $this->valueFormatter,
            closureExporter: $this->closureExporter,
        );

        $this->objectExporter = $objectExporter->withValueExporter($this);
        $this->arrayExporter = new ArrayExporter(
            $config,
            $this->closureExporter,
            $this->objectExporter,
            $this->valueFormatter,
            $this,
        );
    }

    public function export(mixed $var): string
    {
        return $this->exportValue($var, ExportContext::root());
    }

    public function exportValue(mixed $value, ExportContext $context): string
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
            is_null($value) => $this->valueFormatter->formatNull(),
            is_bool($value) => $this->valueFormatter->formatBool($value),
            is_int($value), is_float($value) => $this->valueFormatter->formatNumeric($value),
            is_string($value) => $this->valueFormatter->escapeString($value),
            is_array($value) => $this->arrayExporter instanceof ArrayExporter
                ? $this->arrayExporter->exportWithContext($value, $context)
                : $this->arrayExporter->exportAtDepth($value, $context->depth, $context->baseIndent),
            $value instanceof Closure => $this->closureExporter instanceof ContextualClosureExporterInterface
                ? $this->closureExporter->exportWithContext($value, $context)
                : $this->closureExporter->exportWithDepth($value, $context->depth),
            is_object($value) => $this->objectExporter->exportWithContext($value, $context),
            is_resource($value) => throw ExportException::resourceNotExportable($value),
            default => throw ExportException::unsupportedType($value),
        };
    }

    public function exportToFile(mixed $var): string
    {
        return $this->export($var) . ';';
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self(
            $config,
            $this->sourceCache,
            $this->objectExporter->withConfig($config),
            $this->valueFormatter,
            $this->closureExporter->withConfig($config),
        );
    }

    public function getConfig(): ExportConfig
    {
        return $this->config;
    }

    public function getArrayExporter(): ArrayExporterInterface
    {
        return $this->arrayExporter;
    }

    public function getClosureExporter(): ClosureExporterInterface
    {
        return $this->closureExporter;
    }

    public function getObjectExporter(): ObjectExporterInterface
    {
        return $this->objectExporter;
    }
}
