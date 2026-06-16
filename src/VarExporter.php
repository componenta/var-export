<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\AstCache;
use Componenta\VarExport\Internal\ValueFormatter;

/**
 * Exports PHP variables to their string representation.
 *
 * This is the main exporter class. For repeated exports with the same
 * configuration, reuse the instance to benefit from AST caching.
 *
 * Example:
 * ```php
 * $exporter = new VarExporter(ExportConfig::pretty());
 * $code = $exporter->export($array);
 * ```
 *
 * For one-off exports, use the static Export facade instead.
 *
 * @see Export
 */
final readonly class VarExporter
{
    private ValueFormatterInterface $valueFormatter;
    private ArrayExporterInterface $arrayExporter;
    private ClosureExporterInterface $closureExporter;
    private ?ObjectExporterInterface $objectExporter;
    private AstCache $astCache;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?AstCache $astCache = null,
        ?ObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
        $this->astCache = $astCache ?? new AstCache();
        $this->closureExporter = new ClosureExporter($config, $this->astCache);

        // When no object exporter is supplied we default to our own and
        // wire a lazy provider so nested arrays inside readonly objects
        // reuse the main ArrayExporter (pretty/sortKeys/trailingComma
        // remain consistent with the outer structure). The provider
        // captures `$this`; at call time `$this->arrayExporter` is
        // already assigned below.
        if ($objectExporter === null) {
            $objectExporter = new ObjectExporter(
                $config,
                $this->valueFormatter,
                fn(): ArrayExporterInterface => $this->arrayExporter,
            );
        }

        $this->objectExporter = $objectExporter;
        $this->arrayExporter = new ArrayExporter(
            $config,
            $this->closureExporter,
            $objectExporter,
            $this->valueFormatter,
        );
    }

    /**
     * Export any variable to its string representation.
     *
     * @throws ExportException If the variable cannot be exported
     */
    public function export(mixed $var): string
    {
        return match (true) {
            is_null($var) => $this->valueFormatter->formatNull(),
            is_bool($var) => $this->valueFormatter->formatBool($var),
            is_int($var), is_float($var) => $this->valueFormatter->formatNumeric($var),
            is_string($var) => $this->valueFormatter->escapeString($var),
            is_array($var) => $this->arrayExporter->export($var),
            $var instanceof Closure => $this->closureExporter->export($var),
            is_object($var) && $this->objectExporter?->supports($var) => $this->objectExporter->export($var),
            is_object($var) => throw ExportException::unexportableObject($var),
            is_resource($var) => throw ExportException::resourceNotExportable($var),
            default => throw ExportException::unsupportedType($var),
        };
    }

    /**
     * Export with trailing semicolon (for file output).
     *
     * @throws ExportException If the variable cannot be exported
     */
    public function exportToFile(mixed $var): string
    {
        return $this->export($var) . ';';
    }

    /**
     * Create a new exporter with different configuration.
     */
    public function withConfig(ExportConfig $config): self
    {
        return new self($config, $this->astCache, $this->objectExporter);
    }

    /**
     * Get current configuration.
     */
    public function getConfig(): ExportConfig
    {
        return $this->config;
    }

    /**
     * Get the array exporter instance.
     */
    public function getArrayExporter(): ArrayExporterInterface
    {
        return $this->arrayExporter;
    }

    /**
     * Get the closure exporter instance.
     */
    public function getClosureExporter(): ClosureExporterInterface
    {
        return $this->closureExporter;
    }
}
