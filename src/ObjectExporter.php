<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ValueFormatter;
use ReflectionClass;
use UnitEnum;

/**
 * Exports simple value objects and enums to their PHP representation.
 *
 * Supports:
 * - Readonly classes with public constructor properties -> `new \ClassName(arg1, arg2)`
 * - Enum cases -> `\ClassName::CaseName`
 *
 * Does NOT support:
 * - Mutable objects, objects with private state, closures as properties
 *
 * Nested arrays in object properties are delegated to the main
 * {@see ArrayExporterInterface} when an `$arrayExporterProvider` callback is
 * supplied - that keeps pretty/sortKeys/trailingComma consistent with the
 * surrounding export. The provider is a Closure (not a direct dependency)
 * so ObjectExporter and ArrayExporter can reference each other without
 * creating a construction-time cycle.
 */
final readonly class ObjectExporter implements ObjectExporterInterface
{
    private ValueFormatterInterface $valueFormatter;
    private ?Closure $arrayExporterProvider;

    /**
     * @param Closure(): ArrayExporterInterface|null $arrayExporterProvider
     *        Invoked lazily to obtain the ArrayExporter used for nested
     *        array values. When null, nested arrays fall back to a simple
     *        compact representation.
     */
    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ValueFormatterInterface $valueFormatter = null,
        ?Closure $arrayExporterProvider = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
        $this->arrayExporterProvider = $arrayExporterProvider;
    }

    public function export(object $object): string
    {
        return $this->exportWithDepth($object, 0);
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        if ($depth > $this->config->maxDepth) {
            throw new ExportException(
                sprintf(
                    'Maximum nesting depth of %d exceeded while exporting object of type "%s".',
                    $this->config->maxDepth,
                    $object::class,
                ),
                ['class' => $object::class, 'max_depth' => $this->config->maxDepth, 'depth' => $depth],
            );
        }

        if ($object instanceof UnitEnum) {
            return $this->exportEnum($object);
        }

        return $this->exportObject($object, $depth);
    }

    public function supports(object $object): bool
    {
        if ($object instanceof UnitEnum) {
            return true;
        }

        $reflection = new ReflectionClass($object);

        if (!$reflection->isReadOnly()) {
            return false;
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return true;
        }

        // Every constructor parameter must map to a public property so we
        // can round-trip the value via `new ClassName(...)`.
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (!$reflection->hasProperty($name) || !$reflection->getProperty($name)->isPublic()) {
                return false;
            }
        }

        return true;
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self($config, $this->valueFormatter, $this->arrayExporterProvider);
    }

    private function exportEnum(UnitEnum $enum): string
    {
        return '\\' . $enum::class . '::' . $enum->name;
    }

    private function exportObject(object $object, int $depth): string
    {
        $reflection = new ReflectionClass($object);

        if (!$reflection->isReadOnly()) {
            throw new ExportException(
                sprintf(
                    'Cannot export object of type "%s": only readonly classes are supported.',
                    $object::class,
                ),
                ['class' => $object::class],
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return 'new \\' . $object::class . '()';
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (!$reflection->hasProperty($name) || !$reflection->getProperty($name)->isPublic()) {
                throw new ExportException(
                    sprintf(
                        'Cannot export object of type "%s": constructor parameter "$%s" has no corresponding public property.',
                        $object::class,
                        $name,
                    ),
                    ['class' => $object::class, 'parameter' => $name],
                );
            }

            $value = $reflection->getProperty($name)->getValue($object);
            $args[] = $this->exportValue($value, $depth + 1, $name, $object::class);
        }

        $className = '\\' . $object::class;

        if ($args === []) {
            return "new {$className}()";
        }

        if ($this->config->isPretty() && count($args) > 1) {
            return $this->formatPretty($className, $args, $depth);
        }

        return "new {$className}(" . implode(', ', $args) . ')';
    }

    /**
     * @param array<int, string> $args
     */
    private function formatPretty(string $className, array $args, int $depth): string
    {
        $baseIndent = str_repeat($this->config->indent, $depth);
        $itemIndent = str_repeat($this->config->indent, $depth + 1);
        $trailing = $this->config->trailingComma ? ',' : '';

        $formatted = array_map(
            static fn(string $arg): string => $itemIndent . $arg,
            $args,
        );

        return "new {$className}(\n" . implode(",\n", $formatted) . $trailing . "\n{$baseIndent})";
    }

    /**
     * @throws ExportException
     */
    private function exportValue(mixed $value, int $depth, string $paramName, string $className): string
    {
        return match (true) {
            is_null($value) => $this->valueFormatter->formatNull(),
            is_bool($value) => $this->valueFormatter->formatBool($value),
            is_int($value), is_float($value) => $this->valueFormatter->formatNumeric($value),
            is_string($value) => $this->valueFormatter->escapeString($value),
            is_array($value) => $this->exportArray($value, $depth),
            $value instanceof UnitEnum => $this->exportEnum($value),
            is_object($value) => $this->exportWithDepth($value, $depth),
            default => throw new ExportException(
                sprintf(
                    'Cannot export property "%s::$%s": unsupported type "%s".',
                    $className,
                    $paramName,
                    get_debug_type($value),
                ),
                ['class' => $className, 'property' => $paramName, 'type' => get_debug_type($value)],
            ),
        };
    }

    /**
     * Format a nested array. Delegates to the main ArrayExporter when a
     * provider was supplied so user-visible formatting (pretty layout,
     * sortKeys, trailingComma) stays consistent with the top level.
     *
     * The fallback path - used when no provider is wired - produces a
     * minimal compact array so that standalone ObjectExporter use still
     * yields valid PHP.
     */
    private function exportArray(array $array, int $depth): string
    {
        if ($this->arrayExporterProvider !== null) {
            $arrayExporter = ($this->arrayExporterProvider)();
            $baseIndent = str_repeat($this->config->indent, $depth);

            return $arrayExporter->exportAtDepth($array, $depth, $baseIndent);
        }

        return $this->formatArrayCompact($array, $depth);
    }

    private function formatArrayCompact(array $array, int $depth): string
    {
        if ($array === []) {
            return '[]';
        }

        $isSequential = array_is_list($array);
        $items = [];

        foreach ($array as $key => $value) {
            $exported = $this->exportValue($value, $depth + 1, (string) $key, 'array');

            if ($isSequential) {
                $items[] = $exported;
            } else {
                $formattedKey = is_int($key)
                    ? (string) $key
                    : $this->valueFormatter->escapeString($key);
                $items[] = "{$formattedKey} => {$exported}";
            }
        }

        return '[' . implode(', ', $items) . ']';
    }
}
