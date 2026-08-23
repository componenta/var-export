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
use Componenta\VarExport\Internal\ValueFormatter;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use SplObjectStorage;
use Throwable;
use UnitEnum;

final readonly class ObjectExporter implements ObjectExporterInterface
{
    private ValueFormatterInterface $valueFormatter;

    /**
     * @param (Closure(): ArrayExporterInterface)|null $arrayExporterProvider
     *        Legacy extension point retained for source compatibility. The
     *        returned exporter is always reconfigured before use.
     */
    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ValueFormatterInterface $valueFormatter = null,
        private ?Closure $arrayExporterProvider = null,
        private ?ClosureExporterInterface $closureExporter = null,
    ) {
        $this->valueFormatter = $valueFormatter ?? new ValueFormatter();
    }

    public function export(object $object): string
    {
        return $this->exportWithDepth($object, 0);
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        if ($depth < 0) {
            throw new ExportException(sprintf('Depth must be non-negative; got %d.', $depth), ['depth' => $depth]);
        }

        /** @var SplObjectStorage<object, null> $seen */
        $seen = new SplObjectStorage();

        return $this->doExport($object, $depth, $seen);
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
        );
    }

    /** @param SplObjectStorage<object, null> $seen */
    private function doExport(object $object, int $depth, SplObjectStorage $seen): string
    {
        if ($depth > $this->config->maxDepth) {
            throw new ExportException(
                sprintf('Maximum nesting depth of %d exceeded while exporting "%s".', $this->config->maxDepth, $object::class),
                ['class' => $object::class, 'max_depth' => $this->config->maxDepth, 'depth' => $depth],
            );
        }

        if ($object instanceof UnitEnum) {
            return '\\' . $object::class . '::' . $object->name;
        }

        if ($seen->contains($object)) {
            throw ExportException::objectCycle($object::class, $depth);
        }
        $seen->attach($object);

        if ($object instanceof Closure) {
            if ($this->closureExporter === null) {
                throw new ExportException('Cannot export Closure property without a ClosureExporterInterface.');
            }

            return $this->closureExporter->exportWithDepth($object, $depth);
        }

        return $this->exportReadonlyObject($object, $depth, $seen);
    }

    /** @param SplObjectStorage<object, null> $seen */
    private function exportReadonlyObject(object $object, int $depth, SplObjectStorage $seen): string
    {
        $reflection = new ReflectionClass($object);
        $this->assertReconstructableClass($reflection, $object);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return 'new \\' . $reflection->getName() . '()';
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $value = $property->getValue($object);
            $args[] = $this->exportValue($value, $depth + 1, $seen);
        }

        $class = '\\' . $reflection->getName();
        if ($args === []) {
            return "new {$class}()";
        }

        if ($this->config->isPretty() && (count($args) > 1 || $this->containsMultilineArgument($args))) {
            return $this->formatPretty($class, $args, $depth);
        }

        return "new {$class}(" . implode(', ', $args) . ')';
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertReconstructableClass(ReflectionClass $reflection, object $object): void
    {
        if (!$reflection->isReadOnly()) {
            throw ExportException::unexportableObject($object);
        }

        if ($reflection->isAnonymous()) {
            throw new ExportException(
                sprintf('Anonymous readonly class "%s" cannot be named in generated PHP source.', $reflection->getName()),
                ['class' => $reflection->getName()],
            );
        }

        $constructor = $reflection->getConstructor();
        $properties = $this->instanceProperties($reflection);

        if ($constructor === null) {
            if ($properties !== []) {
                throw new ExportException(
                    sprintf('Readonly class "%s" has state but no constructor that can reconstruct it.', $reflection->getName()),
                    ['class' => $reflection->getName()],
                );
            }

            return;
        }

        if (!$constructor->isPublic()) {
            throw new ExportException(
                sprintf('Constructor of readonly class "%s" must be public.', $reflection->getName()),
                ['class' => $reflection->getName()],
            );
        }

        $parameterNames = [];
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertReconstructableParameter($reflection, $object, $parameter);
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
                    ['class' => $reflection->getName(), 'property' => $property->getName()],
                );
            }
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
    private function assertReconstructableParameter(
        ReflectionClass $reflection,
        object $object,
        ReflectionParameter $parameter,
    ): void {
        $name = $parameter->getName();

        if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
            throw new ExportException(
                sprintf('Constructor parameter "%s::$%s" cannot be variadic or passed by reference.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'parameter' => $name],
            );
        }

        if (!$parameter->isPromoted() || !$reflection->hasProperty($name)) {
            throw new ExportException(
                sprintf('Constructor parameter "%s::$%s" must be a promoted property.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'parameter' => $name],
            );
        }

        $property = $reflection->getProperty($name);
        if (!$property->isPublic() || !$property->isPromoted() || $property->isVirtual() || $property->hasHooks()) {
            throw new ExportException(
                sprintf('Promoted property "%s::$%s" must be public, concrete, and hook-free.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'property' => $name],
            );
        }

        if (!$property->isInitialized($object)) {
            throw new ExportException(
                sprintf('Promoted property "%s::$%s" is not initialized.', $reflection->getName(), $name),
                ['class' => $reflection->getName(), 'property' => $name],
            );
        }
    }

    /** @param SplObjectStorage<object, null> $seen */
    private function exportValue(mixed $value, int $depth, SplObjectStorage $seen): string
    {
        return match (true) {
            is_null($value) => $this->valueFormatter->formatNull(),
            is_bool($value) => $this->valueFormatter->formatBool($value),
            is_int($value), is_float($value) => $this->valueFormatter->formatNumeric($value),
            is_string($value) => $this->valueFormatter->escapeString($value),
            is_array($value) => $this->exportArray($value, $depth),
            is_object($value) => $this->doExport($value, $depth, $seen),
            is_resource($value) => throw ExportException::resourceNotExportable($value),
            default => throw ExportException::unsupportedType($value),
        };
    }

    /** @param array<mixed> $array */
    private function exportArray(array $array, int $depth): string
    {
        if ($this->arrayExporterProvider !== null) {
            $arrayExporter = ($this->arrayExporterProvider)();
            if (!$arrayExporter instanceof ArrayExporterInterface) {
                throw new ExportException(
                    sprintf(
                        'ObjectExporter array provider must return %s; got %s.',
                        ArrayExporterInterface::class,
                        get_debug_type($arrayExporter),
                    ),
                    ['type' => get_debug_type($arrayExporter)],
                );
            }

            $arrayExporter = $arrayExporter->withConfig($this->config);
        } else {
            $arrayExporter = new ArrayExporter(
                $this->config,
                $this->closureExporter,
                $this,
                $this->valueFormatter,
            );
        }

        $baseIndent = str_repeat($this->config->indent, $depth);

        return $arrayExporter->exportAtDepth($array, $depth, $baseIndent);
    }

    /** @param list<string> $args */
    private function formatPretty(string $class, array $args, int $depth): string
    {
        $baseIndent = str_repeat($this->config->indent, $depth);
        $itemIndent = str_repeat($this->config->indent, $depth + 1);
        $trailing = $this->config->trailingComma ? ',' : '';
        $formatted = array_map(
            static fn(string $argument): string => $itemIndent . $argument,
            $args,
        );

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
