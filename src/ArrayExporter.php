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
use Componenta\VarExport\Internal\ValueFormatter;

/**
 * Exports PHP arrays to their string representation.
 *
 * Supports both compact and pretty-printed output with configurable
 * indentation, key sorting, and trailing commas.
 */
final readonly class ArrayExporter implements ArrayExporterInterface
{
    private ValueFormatterInterface $valueFormatter;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        private ?ClosureExporterInterface $closureExporter = null,
        private ?ObjectExporterInterface $objectExporter = null,
        ?ValueFormatterInterface $valueFormatter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
    }

    public function export(array $array): string
    {
        return $this->formatArray($array, 0, [], '');
    }

    public function exportAtDepth(array $array, int $depth, string $baseIndent): string
    {
        return $this->formatArray($array, $depth, [], $baseIndent);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self($config, $this->closureExporter, $this->objectExporter, $this->valueFormatter);
    }

    /**
     * Export array with trailing semicolon for file output.
     */
    public function exportWithSemicolon(array $array): string
    {
        return $this->export($array) . ';';
    }

    /**
     * Format array recursively with depth tracking.
     *
     * @param array<int|string> $keyPath Path of keys to current position
     * @param string $baseIndent Indentation prefix for the closing bracket
     * @throws ArrayExportException
     */
    private function formatArray(array $array, int $depth, array $keyPath, string $baseIndent): string
    {
        if ($depth > $this->config->maxDepth) {
            throw ArrayExportException::maxDepthExceeded($this->config->maxDepth, $depth, $keyPath);
        }

        if ($array === []) {
            return '[]';
        }

        $isSequential = array_is_list($array);
        $keys = $this->getOrderedKeys($array, $isSequential);

        return $this->config->isPretty()
            ? $this->formatPretty($array, $keys, $isSequential, $depth, $keyPath, $baseIndent)
            : $this->formatCompact($array, $keys, $isSequential, $depth, $keyPath, $baseIndent);
    }

    /**
     * Format array in compact single-line style.
     *
     * @param array<int|string> $keyPath
     */
    private function formatCompact(
        array $array,
        array $keys,
        bool $isSequential,
        int $depth,
        array $keyPath,
        string $baseIndent,
    ): string {
        $items = [];
        $childBaseIndent = $baseIndent . $this->config->indent;

        foreach ($keys as $key) {
            $currentPath = [...$keyPath, $key];
            $value = $this->formatValue($array[$key], $depth + 1, $key, $currentPath, $childBaseIndent);

            if ($isSequential) {
                $items[] = $value;
            } else {
                $formattedKey = $this->formatKey($key);
                $items[] = "{$formattedKey} => {$value}";
            }
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * Format array in pretty multi-line style.
     *
     * @param array<int|string> $keyPath
     */
    private function formatPretty(
        array $array,
        array $keys,
        bool $isSequential,
        int $depth,
        array $keyPath,
        string $baseIndent,
    ): string {
        $itemIndent = $baseIndent . $this->config->indent;

        $items = [];

        foreach ($keys as $key) {
            $currentPath = [...$keyPath, $key];
            $value = $this->formatValue($array[$key], $depth + 1, $key, $currentPath, $itemIndent);

            if ($isSequential) {
                $items[] = $itemIndent . $value;
            } else {
                $formattedKey = $this->formatKey($key);
                $items[] = $itemIndent . "{$formattedKey} => {$value}";
            }
        }

        $trailing = $this->config->trailingComma ? ',' : '';

        return "[\n" . implode(",\n", $items) . $trailing . "\n{$baseIndent}]";
    }

    /**
     * Format a single value.
     *
     * @param int|string $key Current array key
     * @param array<int|string> $keyPath Full path to this value
     * @param string $baseIndent Base indent at this nesting level (for pretty mode)
     * @throws ArrayExportException
     */
    private function formatValue(
        mixed $value,
        int $depth,
        int|string $key,
        array $keyPath,
        string $baseIndent,
    ): string {
        return match (true) {
            is_null($value) => $this->valueFormatter->formatNull(),
            is_bool($value) => $this->valueFormatter->formatBool($value),
            is_int($value), is_float($value) => $this->valueFormatter->formatNumeric($value),
            is_string($value) => $this->valueFormatter->escapeString($value),
            is_array($value) => $this->formatArray($value, $depth, $keyPath, $baseIndent),
            $value instanceof Closure => $this->formatClosure($value, $depth),
            is_object($value) && $this->objectExporter?->supports($value) => $this->objectExporter->exportWithDepth($value, $depth),
            is_object($value) => throw ArrayExportException::unexportableElement(
                $key,
                $value::class,
                $depth,
                $keyPath,
            ),
            is_resource($value) => throw ArrayExportException::unexportableElement(
                $key,
                'resource (' . get_resource_type($value) . ')',
                $depth,
                $keyPath,
            ),
            default => throw ArrayExportException::unexportableElement(
                $key,
                get_debug_type($value),
                $depth,
                $keyPath,
            ),
        };
    }

    /**
     * Format a closure value.
     */
    private function formatClosure(Closure $closure, int $depth): string
    {
        if ($this->closureExporter === null) {
            return $this->createClosurePlaceholder($closure);
        }

        return $this->closureExporter->exportWithDepth($closure, $depth);
    }

    /**
     * Create a placeholder comment for closures when no exporter is available.
     */
    private function createClosurePlaceholder(Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);
            $params = array_map(
                fn($p) => '$' . $p->getName(),
                $reflection->getParameters(),
            );

            return sprintf('function(%s) { /* closure */ }', implode(', ', $params));
        } catch (\Throwable) {
            return 'function() { /* closure */ }';
        }
    }

    /**
     * Format an array key.
     */
    private function formatKey(int|string $key): string
    {
        return is_int($key) ? (string) $key : $this->valueFormatter->escapeString($key);
    }

    /**
     * Get array keys in the desired order.
     *
     * @return array<int|string>
     */
    private function getOrderedKeys(array $array, bool $isSequential): array
    {
        if ($isSequential && !$this->config->sortKeys) {
            // Sequential list keys are already 0..n-1 in order.
            return array_keys($array);
        }

        $keys = array_keys($array);

        if (!$this->config->sortKeys) {
            return $keys;
        }

        usort($keys, static function (int|string $a, int|string $b): int {
            // Numeric keys first, then string keys alphabetically.
            $aIsInt = is_int($a);
            $bIsInt = is_int($b);

            if ($aIsInt && !$bIsInt) {
                return -1;
            }

            if (!$aIsInt && $bIsInt) {
                return 1;
            }

            return $a <=> $b;
        });

        return $keys;
    }
}
