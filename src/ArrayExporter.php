<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Internal\ArrayKeyOrder;
use Componenta\VarExport\Internal\ValueFormatter;
use Throwable;

final readonly class ArrayExporter implements ArrayExporterInterface
{
    private ValueFormatterInterface $valueFormatter;

    /** @param (Closure(mixed, ExportContext): string)|null $valueExporter */
    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        private ?ClosureExporterInterface $closureExporter = null,
        private ?ObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
        private ?Closure $valueExporter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
    }

    /** @param array<mixed> $array */
    public function export(array $array): string
    {
        return $this->exportWithContext($array, ExportContext::root());
    }

    /** @param array<mixed> $array */
    public function exportAtDepth(array $array, int $depth, string $baseIndent): string
    {
        if ($depth < 0) {
            throw ArrayExportException::invalidDepth($depth);
        }

        return $this->exportWithContext($array, new ExportContext($depth, baseIndent: $baseIndent));
    }

    /** @param array<mixed> $array */
    public function exportWithContext(array $array, ExportContext $context): string
    {
        return $this->formatArray($array, $context);
    }

    public function withConfig(ExportConfig $config): static
    {
        $closureExporter = $this->closureExporter instanceof ClosureExporter
            ? $this->closureExporter->withConfig($config)
            : $this->closureExporter;
        $objectExporter = $this->objectExporter instanceof ObjectExporter
            ? $this->objectExporter->withConfig($config)
            : $this->objectExporter;

        return new self(
            $config,
            $closureExporter,
            $objectExporter,
            $this->valueFormatter,
        );
    }

    /** @param array<mixed> $array */
    private function formatArray(array $array, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw ArrayExportException::maxDepthExceeded(
                $this->config->maxDepth,
                $context->depth,
                $context->path,
            );
        }

        if ($array === []) {
            return '[]';
        }

        $isList = array_is_list($array);
        $keys = ArrayKeyOrder::orderedKeys($array, $this->config->sortKeys);

        return $this->config->isPretty()
            ? $this->formatPretty($array, $keys, $isList, $context)
            : $this->formatCompact($array, $keys, $isList, $context);
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $keys
     */
    private function formatCompact(array $array, array $keys, bool $isList, ExportContext $context): string
    {
        $items = [];
        $childIndent = $context->baseIndent . $this->config->indent;

        foreach ($keys as $key) {
            $child = $context->child($key, $childIndent);
            $this->assertNotReference($array, $key, $child->path);
            $value = $this->formatValue($array[$key], $key, $child);
            $items[] = $isList ? $value : $this->formatKey($key) . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $keys
     */
    private function formatPretty(array $array, array $keys, bool $isList, ExportContext $context): string
    {
        $itemIndent = $context->baseIndent . $this->config->indent;
        $items = [];

        foreach ($keys as $key) {
            $child = $context->child($key, $itemIndent);
            $this->assertNotReference($array, $key, $child->path);
            $value = $this->formatValue($array[$key], $key, $child);
            $items[] = $itemIndent . ($isList ? $value : $this->formatKey($key) . ' => ' . $value);
        }

        $trailing = $this->config->trailingComma ? ',' : '';

        return "[\n" . implode(",\n", $items) . $trailing . "\n{$context->baseIndent}]";
    }

    private function formatValue(mixed $value, int|string $key, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw ArrayExportException::maxDepthExceeded(
                $this->config->maxDepth,
                $context->depth,
                $context->path,
            );
        }

        if ($this->valueExporter !== null) {
            return ($this->valueExporter)($value, $context);
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value), is_string($value) => $this->valueFormatter->format($value),
            is_array($value) => $this->formatArray($value, $context),
            $value instanceof Closure => $this->formatClosure($value, $key, $context),
            is_object($value) => $this->formatObject($value, $key, $context),
            is_resource($value) => throw ArrayExportException::unexportableElement(
                $key,
                'resource (' . get_resource_type($value) . ')',
                $context->depth,
                $context->path,
            ),
            default => throw ArrayExportException::unexportableElement(
                $key,
                get_debug_type($value),
                $context->depth,
                $context->path,
            ),
        };
    }

    private function formatClosure(Closure $closure, int|string $key, ExportContext $context): string
    {
        if ($this->closureExporter === null) {
            throw ArrayExportException::closureExporterMissing($key, $context->depth, $context->path);
        }

        return $this->closureExporter instanceof ClosureExporter
            ? $this->closureExporter->exportWithContext($closure, $context)
            : $this->closureExporter->export($closure);
    }

    private function formatObject(object $object, int|string $key, ExportContext $context): string
    {
        if ($this->objectExporter === null) {
            throw ArrayExportException::unexportableElement(
                $key,
                $object::class,
                $context->depth,
                $context->path,
            );
        }

        try {
            return $this->objectExporter instanceof ObjectExporter
                ? $this->objectExporter->exportWithContext($object, $context)
                : $this->objectExporter->export($object);
        } catch (Throwable $e) {
            throw ArrayExportException::unexportableElement(
                $key,
                $object::class,
                $context->depth,
                $context->path,
                $e,
            );
        }
    }

    private function formatKey(int|string $key): string
    {
        return $this->valueFormatter->format($key);
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $keyPath
     */
    private function assertNotReference(array $array, int|string $key, array $keyPath): void
    {
        if (\ReflectionReference::fromArrayElement($array, $key) !== null) {
            throw ArrayExportException::referencedElement($key, $keyPath);
        }
    }
}
