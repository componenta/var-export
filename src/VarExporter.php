<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ArrayKeyOrder;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use UnitEnum;

final readonly class VarExporter
{
    private ClosureExporter $closureExporter;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        private ?ObjectExporterInterface $objectExporter = null,
    ) {
        $this->closureExporter = new ClosureExporter($config);
    }

    public function export(mixed $var): string
    {
        return $this->exportValue($var, ExportContext::root());
    }

    private function exportValue(mixed $value, ExportContext $context): string
    {
        $this->assertDepth($context);

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value), is_string($value) => self::formatPrimitive($value),
            is_array($value) => $this->exportArray($value, $context),
            $value instanceof Closure => $this->closureExporter->exportWithContext($value, $context),
            $value instanceof UnitEnum => '\\' . $value::class . '::' . $value->name,
            is_object($value) => $this->exportObject($value, $context),
            is_resource($value) => throw ExportException::resourceNotExportable($value),
            default => throw ExportException::unsupportedType($value),
        };
    }

    private function assertDepth(ExportContext $context): void
    {
        if ($context->depth <= $this->config->maxDepth) {
            return;
        }

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

    /** @param array<mixed> $array */
    private function exportArray(array $array, ExportContext $context): string
    {
        if ($array === []) {
            return '[]';
        }

        $isList = array_is_list($array);
        $keys = ArrayKeyOrder::orderedKeys($array, $this->config->sortKeys);

        return $this->config->isPretty()
            ? $this->exportPrettyArray($array, $keys, $isList, $context)
            : $this->exportCompactArray($array, $keys, $isList, $context);
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $keys
     */
    private function exportCompactArray(array $array, array $keys, bool $isList, ExportContext $context): string
    {
        $items = [];
        $childIndent = $context->baseIndent . $this->config->indent;

        foreach ($keys as $key) {
            $child = $context->child($key, $childIndent);
            $this->assertArrayElementIsNotReference($array, $key, $child->path);
            $value = $this->exportValue($array[$key], $child);
            $items[] = $isList ? $value : self::formatPrimitive($key) . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $keys
     */
    private function exportPrettyArray(array $array, array $keys, bool $isList, ExportContext $context): string
    {
        $itemIndent = $context->baseIndent . $this->config->indent;
        $items = [];

        foreach ($keys as $key) {
            $child = $context->child($key, $itemIndent);
            $this->assertArrayElementIsNotReference($array, $key, $child->path);
            $value = $this->exportValue($array[$key], $child);
            $items[] = $itemIndent . ($isList ? $value : self::formatPrimitive($key) . ' => ' . $value);
        }

        $trailing = $this->config->trailingComma ? ',' : '';

        return "[\n" . implode(",\n", $items) . $trailing . "\n{$context->baseIndent}]";
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $path
     */
    private function assertArrayElementIsNotReference(array $array, int|string $key, array $path): void
    {
        if (\ReflectionReference::fromArrayElement($array, $key) !== null) {
            throw ArrayExportException::referencedElement($key, $path);
        }
    }

    private function exportObject(object $object, ExportContext $context): string
    {
        if ($this->objectExporter !== null) {
            return $this->objectExporter->export($object);
        }

        if (!$this->config->allowGenericReadonlyObjects) {
            throw new ExportException(
                sprintf(
                    'Generic readonly-object export is disabled for "%s" at %s. Register an ObjectExporterInterface or explicitly enable generic readonly objects.',
                    $object::class,
                    $context->location(),
                ),
                ['class' => $object::class, 'path' => $context->path],
            );
        }

        if ($context->activeObjects->offsetExists($object)) {
            throw ExportException::objectCycle($object::class, $context->depth);
        }

        $context->activeObjects->offsetSet($object);
        try {
            return $this->exportReadonlyObject($object, $context);
        } finally {
            $context->activeObjects->offsetUnset($object);
        }
    }

    private function exportReadonlyObject(object $object, ExportContext $context): string
    {
        $reflection = new ReflectionClass($object);
        $this->assertReconstructableClass($reflection, $object, $context);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return 'new \\' . $reflection->getName() . '()';
        }

        $args = [];
        $argumentIndent = $context->baseIndent . $this->config->indent;
        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $args[] = $this->exportValue(
                $property->getValue($object),
                $context->child($parameter->getName(), $argumentIndent),
            );
        }

        $class = '\\' . $reflection->getName();
        if ($args === []) {
            return "new {$class}()";
        }

        if ($this->config->isPretty() && (count($args) > 1 || self::containsMultilineArgument($args))) {
            return $this->formatPrettyObject($class, $args, $context);
        }

        return "new {$class}(" . implode(', ', $args) . ')';
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertReconstructableClass(
        ReflectionClass $reflection,
        object $object,
        ExportContext $context,
    ): void {
        if (!$reflection->isUserDefined()) {
            throw new ExportException(
                sprintf(
                    'Internal/extension class "%s" cannot use generic constructor reconstruction; register an ObjectExporterInterface.',
                    $reflection->getName(),
                ),
                ['class' => $reflection->getName(), 'path' => $context->path],
            );
        }

        if (!$reflection->isReadOnly()) {
            throw ExportException::unexportableObject($object);
        }

        if ($reflection->isAnonymous()) {
            throw new ExportException(
                sprintf('Anonymous readonly class "%s" cannot be named in generated PHP source.', $reflection->getName()),
                ['class' => $reflection->getName(), 'path' => $context->path],
            );
        }

        if ($reflection->isUninitializedLazyObject($object)) {
            throw new ExportException(
                sprintf('Uninitialized lazy readonly object "%s" cannot be inspected without running its initializer.', $reflection->getName()),
                ['class' => $reflection->getName(), 'path' => $context->path],
            );
        }

        $constructor = $reflection->getConstructor();
        $properties = self::instanceProperties($reflection);

        if ($constructor === null) {
            if ($properties !== []) {
                throw new ExportException(
                    sprintf(
                        'Readonly class "%s" has state but no constructor that can reconstruct it at %s.',
                        $reflection->getName(),
                        $context->location(),
                    ),
                    ['class' => $reflection->getName(), 'path' => $context->path],
                );
            }

            return;
        }

        if (!$constructor->isPublic()) {
            throw new ExportException(
                sprintf('Constructor of readonly class "%s" must be public.', $reflection->getName()),
                ['class' => $reflection->getName(), 'path' => $context->path],
            );
        }

        /** @var array<string, true> $parameterNames */
        $parameterNames = [];
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertReconstructableParameter($reflection, $object, $parameter, $context);
            $parameterNames[$parameter->getName()] = true;
        }

        foreach ($properties as $property) {
            if (!isset($parameterNames[$property->getName()])) {
                throw new ExportException(
                    sprintf(
                        'Readonly class "%s" has instance property "$%s" that is not represented by its constructor.',
                        $reflection->getName(),
                        $property->getName(),
                    ),
                    [
                        'class' => $reflection->getName(),
                        'property' => $property->getName(),
                        'path' => $context->path,
                    ],
                );
            }
        }

        if ($reflection->hasMethod('__unserialize')) {
            throw new ExportException(
                sprintf(
                    'Readonly class "%s" defines __unserialize(); generic constructor reconstruction is not a safe hydration contract.',
                    $reflection->getName(),
                ),
                ['class' => $reflection->getName(), 'path' => $context->path],
            );
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<ReflectionProperty>
     */
    private static function instanceProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        $class = $reflection;

        do {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $properties[] = $property;
            }

            $class = $class->getParentClass();
        } while ($class !== false);

        return $properties;
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertReconstructableParameter(
        ReflectionClass $reflection,
        object $object,
        ReflectionParameter $parameter,
        ExportContext $context,
    ): void {
        $name = $parameter->getName();

        if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
            throw new ExportException(
                sprintf(
                    'Constructor parameter "%s::$%s" cannot be variadic or passed by reference.',
                    $reflection->getName(),
                    $name,
                ),
                ['class' => $reflection->getName(), 'parameter' => $name, 'path' => $context->path],
            );
        }

        if (!$parameter->isPromoted() || !$reflection->hasProperty($name)) {
            throw new ExportException(
                sprintf('Constructor parameter "%s::$%s" must be a promoted property.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'parameter' => $name, 'path' => $context->path],
            );
        }

        $property = $reflection->getProperty($name);
        if (!$property->isPublic() || !$property->isPromoted() || $property->isVirtual() || $property->hasHooks()) {
            throw new ExportException(
                sprintf('Promoted property "%s::$%s" must be public, concrete, and hook-free.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'property' => $name, 'path' => $context->path],
            );
        }

        if (!$property->isInitialized($object)) {
            throw new ExportException(
                sprintf('Promoted property "%s::$%s" is not initialized.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'property' => $name, 'path' => $context->path],
            );
        }
    }

    /** @param list<string> $args */
    private function formatPrettyObject(string $class, array $args, ExportContext $context): string
    {
        $itemIndent = $context->baseIndent . $this->config->indent;
        $trailing = $this->config->trailingComma ? ',' : '';
        $formatted = array_map(static fn(string $argument): string => $itemIndent . $argument, $args);

        return "new {$class}(\n" . implode(",\n", $formatted) . $trailing . "\n{$context->baseIndent})";
    }

    /** @param list<string> $args */
    private static function containsMultilineArgument(array $args): bool
    {
        foreach ($args as $argument) {
            if (str_contains($argument, "\n")) {
                return true;
            }
        }

        return false;
    }

    private static function formatPrimitive(null|bool|int|float|string $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => var_export($value, true),
            is_float($value) => self::formatFloat($value),
            default => var_export($value, true),
        };
    }

    private static function formatFloat(float $value): string
    {
        if ($value === INF) {
            return '\\INF';
        }

        if ($value === -INF) {
            return '-\\INF';
        }

        if (is_nan($value)) {
            return '\\NAN';
        }

        $formatted = self::normalizeDecimalSeparator(sprintf('%.16G', $value));
        if ($value !== (float) $formatted) {
            $formatted = self::normalizeDecimalSeparator(sprintf('%.17G', $value));
        }

        return preg_match('/^-?[0-9]+$/D', $formatted) === 1
            ? $formatted . '.0'
            : $formatted;
    }

    private static function normalizeDecimalSeparator(string $value): string
    {
        $decimalPoint = localeconv()['decimal_point'];
        if ($decimalPoint === '' || $decimalPoint === '.') {
            return $value;
        }

        return str_replace($decimalPoint, '.', $value);
    }
}
