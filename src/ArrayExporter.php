<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Internal\ValueFormatter;
use Throwable;

final readonly class ArrayExporter implements ArrayExporterInterface
{
    private ValueFormatterInterface $valueFormatter;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        private ?ClosureExporterInterface $closureExporter = null,
        private ?ObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
        private ?ContextualValueExporterInterface $valueExporter = null,
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
        return new self(
            $config,
            $this->closureExporter?->withConfig($config),
            $this->objectExporter?->withConfig($config),
            $this->valueFormatter,
            null,
        );
    }

    /** @deprecated Use VarExporter::exportToFile() or Export::toFile(). */
    /** @param array<mixed> $array */
    public function exportWithSemicolon(array $array): string
    {
        return $this->export($array) . ';';
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
        $keys = $this->orderedKeys($array);

        return $this->config->isPretty()
            ? $this->formatPretty($array, $keys, $isList, $context)
            : $this->formatCompact($array, $keys, $isList, $context);
    }

    /** @param array<mixed> $array @param array<int|string> $keys */
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

    /** @param array<mixed> $array @param array<int|string> $keys */
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
        if ($this->valueExporter !== null) {
            return $this->valueExporter->exportValue($value, $context);
        }

        return match (true) {
            is_null($value) => $this->valueFormatter->formatNull(),
            is_bool($value) => $this->valueFormatter->formatBool($value),
            is_int($value), is_float($value) => $this->valueFormatter->formatNumeric($value),
            is_string($value) => $this->valueFormatter->escapeString($value),
            is_array($value) => $this->formatArray($value, $context),
            $value instanceof Closure => $this->formatClosure($value, $key, $context),
            is_object($value) => $this->formatObject($value, $key, $context),
            is_resource($value) => throw ArrayExportException::unexportableElement($key, 'resource (' . get_resource_type($value) . ')', $context->depth, $context->path),
            default => throw ArrayExportException::unexportableElement($key, get_debug_type($value), $context->depth, $context->path),
        };
    }

    private function formatClosure(Closure $closure, int|string $key, ExportContext $context): string
    {
        if ($this->closureExporter === null) {
            throw ArrayExportException::closureExporterMissing($key, $context->depth, $context->path);
        }
        return $this->closureExporter->exportWithDepth($closure, $context->depth);
    }

    private function formatObject(object $object, int|string $key, ExportContext $context): string
    {
        if ($this->objectExporter === null) {
            throw ArrayExportException::unexportableElement($key, $object::class, $context->depth, $context->path);
        }

        try {
            if ($this->objectExporter instanceof ContextualObjectExporterInterface) {
                return $this->objectExporter->exportWithContext($object, $context);
            }
            return $this->objectExporter->exportWithDepth($object, $context->depth);
        } catch (Throwable $e) {
            throw ArrayExportException::unexportableElement($key, $object::class, $context->depth, $context->path, $e);
        }
    }

    private function formatKey(int|string $key): string
    {
        return is_int($key) ? (string) $key : $this->valueFormatter->escapeString($key);
    }

    /** @param array<mixed> $array @return array<int|string> */
    private function orderedKeys(array $array): array
    {
        $keys = array_keys($array);
        if (!$this->config->sortKeys) {
            return $keys;
        }

        usort($keys, static function (int|string $left, int|string $right): int {
            if (is_int($left) && is_string($right)) { return -1; }
            if (is_string($left) && is_int($right)) { return 1; }
            if (is_int($left)) { /** @var int $right */ return $left <=> $right; }
            /** @var string $right */ return strcmp($left, $right);
        });

        return $keys;
    }

    /** @param array<mixed> $array @param array<int|string> $keyPath */
    private function assertNotReference(array $array, int|string $key, array $keyPath): void
    {
        if (\ReflectionReference::fromArrayElement($array, $key) !== null) {
            throw ArrayExportException::referencedElement($key, $keyPath);
        }
    }
}
