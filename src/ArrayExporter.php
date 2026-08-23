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
use Throwable;

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

    /** @param array<mixed> $array */
    public function export(array $array): string
    {
        return $this->formatArray($array, 0, [], '');
    }

    /** @param array<mixed> $array */
    public function exportAtDepth(array $array, int $depth, string $baseIndent): string
    {
        if ($depth < 0) {
            throw ArrayExportException::invalidDepth($depth);
        }

        return $this->formatArray($array, $depth, [], $baseIndent);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self(
            $config,
            $this->closureExporter?->withConfig($config),
            $this->objectExporter?->withConfig($config),
            $this->valueFormatter,
        );
    }

    /**
     * @deprecated Use VarExporter::exportToFile() or Export::toFile().
     */
    /** @param array<mixed> $array */
    public function exportWithSemicolon(array $array): string
    {
        return $this->export($array) . ';';
    }

    /**
     * @param array<mixed> $array
     * @param array<int|string> $keyPath
     */
    private function formatArray(array $array, int $depth, array $keyPath, string $baseIndent): string
    {
        if ($depth > $this->config->maxDepth) {
            throw ArrayExportException::maxDepthExceeded($this->config->maxDepth, $depth, $keyPath);
        }

        if ($array === []) {
            return '[]';
        }

        $isList = array_is_list($array);
        $keys = $this->orderedKeys($array);

        return $this->config->isPretty()
            ? $this->formatPretty($array, $keys, $isList, $depth, $keyPath, $baseIndent)
            : $this->formatCompact($array, $keys, $isList, $depth, $keyPath, $baseIndent);
    }

    /**
     * @param array<int|string> $keys
     * @param array<int|string> $keyPath
     */
    private function formatCompact(
        array $array,
        array $keys,
        bool $isList,
        int $depth,
        array $keyPath,
        string $baseIndent,
    ): string {
        $items = [];
        $childIndent = $baseIndent . $this->config->indent;

        foreach ($keys as $key) {
            $path = [...$keyPath, $key];
            $this->assertNotReference($array, $key, $path);
            $value = $this->formatValue($array[$key], $depth + 1, $key, $path, $childIndent);
            $items[] = $isList ? $value : $this->formatKey($key) . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * @param array<int|string> $keys
     * @param array<int|string> $keyPath
     */
    private function formatPretty(
        array $array,
        array $keys,
        bool $isList,
        int $depth,
        array $keyPath,
        string $baseIndent,
    ): string {
        $itemIndent = $baseIndent . $this->config->indent;
        $items = [];

        foreach ($keys as $key) {
            $path = [...$keyPath, $key];
            $this->assertNotReference($array, $key, $path);
            $value = $this->formatValue($array[$key], $depth + 1, $key, $path, $itemIndent);
            $items[] = $itemIndent . ($isList ? $value : $this->formatKey($key) . ' => ' . $value);
        }

        $trailing = $this->config->trailingComma ? ',' : '';

        return "[\n" . implode(",\n", $items) . $trailing . "\n{$baseIndent}]";
    }

    /**
     * @param array<int|string> $keyPath
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
            $value instanceof Closure => $this->formatClosure($value, $depth, $key, $keyPath),
            is_object($value) => $this->formatObject($value, $depth, $key, $keyPath),
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

    /** @param array<int|string> $keyPath */
    private function formatClosure(Closure $closure, int $depth, int|string $key, array $keyPath): string
    {
        if ($this->closureExporter === null) {
            throw ArrayExportException::closureExporterMissing($key, $depth, $keyPath);
        }

        return $this->closureExporter->exportWithDepth($closure, $depth);
    }

    /** @param array<int|string> $keyPath */
    private function formatObject(object $object, int $depth, int|string $key, array $keyPath): string
    {
        if ($this->objectExporter === null) {
            throw ArrayExportException::unexportableElement($key, $object::class, $depth, $keyPath);
        }

        try {
            return $this->objectExporter->exportWithDepth($object, $depth);
        } catch (Throwable $e) {
            throw ArrayExportException::unexportableElement($key, $object::class, $depth, $keyPath, $e);
        }
    }

    private function formatKey(int|string $key): string
    {
        return is_int($key) ? (string) $key : $this->valueFormatter->escapeString($key);
    }

    /**
     * @param array<mixed> $array
     * @return array<int|string>
     */
    private function orderedKeys(array $array): array
    {
        $keys = array_keys($array);
        if (!$this->config->sortKeys) {
            return $keys;
        }

        usort($keys, static function (int|string $left, int|string $right): int {
            if (is_int($left) && is_string($right)) {
                return -1;
            }

            if (is_string($left) && is_int($right)) {
                return 1;
            }

            if (is_int($left)) {
                /** @var int $right */
                return $left <=> $right;
            }

            /** @var string $right */
            return strcmp($left, $right);
        });

        return $keys;
    }

    /**
     * @param array<mixed> $array
     * @param array<int|string> $keyPath
     */
    private function assertNotReference(array $array, int|string $key, array $keyPath): void
    {
        if (\ReflectionReference::fromArrayElement($array, $key) !== null) {
            throw ArrayExportException::referencedElement($key, $keyPath);
        }
    }
}
