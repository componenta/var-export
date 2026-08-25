<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ValueFormatter;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;
use UnitEnum;

final readonly class ObjectExporter implements ContextualObjectExporterInterface
{
    private ValueFormatterInterface $valueFormatter;

    /**
     * @param (Closure(): ArrayExporterInterface)|null $arrayExporterProvider
     *        Legacy extension point retained for source compatibility.
     */
    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ValueFormatterInterface $valueFormatter = null,
        private ?Closure $arrayExporterProvider = null,
        private ?ClosureExporterInterface $closureExporter = null,
        private ?ContextualValueExporterInterface $valueExporter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
    }

    public function export(object $object): string
    {
        return $this->exportWithContext($object, ExportContext::root());
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        if ($depth < 0) {
            throw new ExportException(sprintf('Depth must be non-negative; got %d.', $depth), ['depth' => $depth]);
        }

        return $this->exportWithContext(
            $object,
            new ExportContext($depth, baseIndent: str_repeat($this->config->indent, $depth)),
        );
    }

    public function exportWithContext(object $object, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw new ExportException(
                sprintf('Maximum nesting depth of %d exceeded while exporting "%s" at %s.', $this->config->maxDepth, $object::class, $context->location()),
                ['class' => $object::class, 'max_depth' => $this->config->maxDepth, 'depth' => $context->depth, 'path' => $context->path],
            );
        }

        if ($object instanceof UnitEnum) {
            return '\\' . $object::class . '::' . $object->name;
        }

        if ($object instanceof Closure) {
            if ($this->closureExporter === null) {
                throw new ExportException(sprintf('Cannot export Closure at %s without a ClosureExporterInterface.', $context->location()), ['path' => $context->path]);
            }

            return $this->closureExporter instanceof ContextualClosureExporterInterface
                ? $this->closureExporter->exportWithContext($object, $context)
                : $this->closureExporter->exportWithDepth($object, $context->depth);
        }

        if (!$this->config->allowGenericReadonlyObjects) {
            throw new ExportException(
                sprintf('Generic readonly-object export is disabled for "%s" at %s. Register a class-specific exporter or explicitly enable generic readonly objects.', $object::class, $context->location()),
                ['class' => $object::class, 'path' => $context->path],
            );
        }

        if ($context->activeObjects->contains($object)) {
            throw ExportException::objectCycle($object::class, $context->depth);
        }

        $context->activeObjects->attach($object);
        try {
            return $this->exportReadonlyObject($object, $context);
        } finally {
            $context->activeObjects->detach($object);
        }
    }

    public function supports(object $object): bool
    {
        try {
            $this->export($object);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self(
            $config,
            $this->valueFormatter,
            $this->arrayExporterProvider,
            $this->closureExporter?->withConfig($config),
            null,
        );
    }

    public function withValueExporter(ContextualValueExporterInterface $valueExporter): static
    {
        return new self(
            $this->config,
            $this->valueFormatter,
            $this->arrayExporterProvider,
            $this->closureExporter,
            $valueExporter,
        );
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
            $value = $property->getValue($object);
            $args[] = $this->exportValue(
                $value,
                $context->child($parameter->getName(), $argumentIndent),
            );
        }

        $class = '\\' . $reflection->getName();
        if ($args === []) {
            return "new {$class}()";
        }
        if ($this->config->isPretty() && (count($args) > 1 || $this->containsMultilineArgument($args))) {
            return $this->formatPretty($class, $args, $context);
        }

        return "new {$class}(" . implode(', ', $args) . ')';
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertReconstructableClass(ReflectionClass $reflection, object $object, ExportContext $context): void
    {
        if (!$reflection->isUserDefined()) {
            throw new ExportException(
                sprintf('Internal/extension class "%s" cannot use generic constructor reconstruction; register a class-specific exporter.', $reflection->getName()),
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

        $constructor = $reflection->getConstructor();
        $properties = $this->instanceProperties($reflection);
        if ($constructor === null) {
            if ($properties !== []) {
                throw new ExportException(
                    sprintf('Readonly class "%s" has state but no constructor that can reconstruct it at %s.', $reflection->getName(), $context->location()),
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
                    sprintf('Readonly class "%s" has instance property "$%s" that is not represented by its constructor.', $reflection->getName(), $property->getName()),
                    ['class' => $reflection->getName(), 'property' => $property->getName(), 'path' => $context->path],
                );
            }
        }
        if ($reflection->hasMethod('__unserialize')) {
            throw new ExportException(
                sprintf('Readonly class "%s" defines __unserialize(); generic constructor reconstruction is not a safe hydration contract.', $reflection->getName()),
                ['class' => $reflection->getName(), 'path' => $context->path],
            );
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<ReflectionProperty>
     */
    private function instanceProperties(ReflectionClass $reflection): array
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
    private function assertReconstructableParameter(ReflectionClass $reflection, object $object, ReflectionParameter $parameter, ExportContext $context): void
    {
        $name = $parameter->getName();
        if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
            throw new ExportException(
                sprintf('Constructor parameter "%s::$%s" cannot be variadic or passed by reference.', $reflection->getName(), $name),
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

    private function exportValue(mixed $value, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw new ExportException(
                sprintf('Maximum nesting depth of %d exceeded at %s.', $this->config->maxDepth, $context->location()),
                ['max_depth' => $this->config->maxDepth, 'depth' => $context->depth, 'path' => $context->path],
            );
        }

        if ($this->valueExporter !== null) {
            return $this->valueExporter->exportValue($value, $context);
        }

        return match (true) {
            is_null($value) => $this->valueFormatter->formatNull(),
            is_bool($value) => $this->valueFormatter->formatBool($value),
            is_int($value), is_float($value) => $this->valueFormatter->formatNumeric($value),
            is_string($value) => $this->valueFormatter->escapeString($value),
            is_array($value) => $this->exportArray($value, $context),
            is_object($value) => $this->exportWithContext($value, $context),
            is_resource($value) => throw ExportException::resourceNotExportable($value),
            default => throw ExportException::unsupportedType($value),
        };
    }

    /** @param array<mixed> $array */
    private function exportArray(array $array, ExportContext $context): string
    {
        if ($this->arrayExporterProvider !== null) {
            $arrayExporter = ($this->arrayExporterProvider)();
            if (!$arrayExporter instanceof ArrayExporterInterface) {
                throw new ExportException(
                    sprintf('ObjectExporter array provider must return %s; got %s.', ArrayExporterInterface::class, get_debug_type($arrayExporter)),
                    ['type' => get_debug_type($arrayExporter), 'path' => $context->path],
                );
            }
            $arrayExporter = $arrayExporter->withConfig($this->config);
        } else {
            $arrayExporter = new ArrayExporter(
                $this->config,
                $this->closureExporter,
                $this,
                $this->valueFormatter,
                $this->valueExporter,
            );
        }

        if ($arrayExporter instanceof ArrayExporter) {
            return $arrayExporter->exportWithContext($array, $context);
        }

        return $arrayExporter->exportAtDepth($array, $context->depth, $context->baseIndent);
    }

    /** @param list<string> $args */
    private function formatPretty(string $class, array $args, ExportContext $context): string
    {
        $baseIndent = $context->baseIndent;
        $itemIndent = $baseIndent . $this->config->indent;
        $trailing = $this->config->trailingComma ? ',' : '';
        $formatted = array_map(static fn(string $argument): string => $itemIndent . $argument, $args);

        return "new {$class}(\n" . implode(",\n", $formatted) . $trailing . "\n{$baseIndent})";
    }

    /** @param list<string> $args */
    private function containsMultilineArgument(array $args): bool
    {
        foreach ($args as $argument) {
            if (str_contains($argument, "\n")) {
                return true;
            }
        }

        return false;
    }
}
