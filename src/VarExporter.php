<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Contract\VarExporterInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ValueFormatter;
use Componenta\VarExport\Source\ClosureSourceCache;

final readonly class VarExporter implements VarExporterInterface
{
    private ValueFormatterInterface $valueFormatter;
    private ArrayExporterInterface $arrayExporter;
    private ClosureExporterInterface $closureExporter;
    private ObjectExporterInterface $objectExporter;
    private ClosureSourceCacheInterface $sourceCache;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ClosureSourceCacheInterface $astCache = null,
        ?ObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
        ?ClosureExporterInterface $closureExporter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
        $this->sourceCache = $astCache ?? new ClosureSourceCache();
        $this->closureExporter = $closureExporter ?? new ClosureExporter($config, $this->sourceCache);
        $this->objectExporter = $objectExporter ?? new ObjectExporter(
            $config,
            $this->valueFormatter,
            closureExporter: $this->closureExporter,
        );
        $this->arrayExporter = new ArrayExporter(
            $config,
            $this->closureExporter,
            $this->objectExporter,
            $this->valueFormatter,
        );
    }

    public function export(mixed $var): string
    {
        return match (true) {
            is_null($var) => $this->valueFormatter->formatNull(),
            is_bool($var) => $this->valueFormatter->formatBool($var),
            is_int($var), is_float($var) => $this->valueFormatter->formatNumeric($var),
            is_string($var) => $this->valueFormatter->escapeString($var),
            is_array($var) => $this->arrayExporter->export($var),
            $var instanceof Closure => $this->closureExporter->export($var),
            is_object($var) => $this->objectExporter->export($var),
            is_resource($var) => throw ExportException::resourceNotExportable($var),
            default => throw ExportException::unsupportedType($var),
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
