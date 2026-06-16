<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Closure;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Exception\ClosureExportException;
use ReflectionFunction;

/**
 * Validates that a closure can be exported with given settings.
 *
 * @internal This class is not part of the public API
 */
final readonly class ClosureValidator
{
    /**
     * Validate closure for export.
     *
     * @throws ClosureExportException If closure cannot be exported
     */
    public function validate(Closure $closure, ClosureUseMode $useMode): ReflectionFunction
    {
        $reflection = new ReflectionFunction($closure);

        $this->validateSource($reflection);
        $this->validateThisBinding($reflection);

        if ($useMode === ClosureUseMode::Inline) {
            $this->validateInlineCapability($reflection);
        }

        return $reflection;
    }

    /**
     * Get captured variables from closure.
     *
     * @return array<string, mixed>
     */
    public function getCapturedVariables(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);

        return $reflection->getClosureUsedVariables();
    }

    /**
     * Check if all captured values can be exported for inlining.
     *
     * @param array<string, mixed> $variables
     * @return array<string, string> Names of variables that cannot be exported, mapped to their types
     */
    public function findUnexportableVariables(array $variables): array
    {
        $unexportable = [];

        foreach ($variables as $name => $value) {
            $type = $this->getUnexportableType($value);
            if ($type !== null) {
                $unexportable[$name] = $type;
            }
        }

        return $unexportable;
    }

    private function validateSource(ReflectionFunction $reflection): void
    {
        $filename = $reflection->getFileName();

        if ($filename === false || !file_exists($filename)) {
            throw ClosureExportException::sourceNotFound($filename ?: 'unknown');
        }
    }

    private function validateThisBinding(ReflectionFunction $reflection): void
    {
        if ($reflection->getClosureThis() !== null) {
            throw ClosureExportException::boundThis($reflection);
        }
    }

    private function validateInlineCapability(ReflectionFunction $reflection): void
    {
        $variables = $reflection->getClosureUsedVariables();

        if (empty($variables)) {
            return;
        }

        $unexportable = $this->findUnexportableVariables($variables);

        if (!empty($unexportable)) {
            throw ClosureExportException::cannotInlineUseVariables(
                $unexportable,
                $reflection->getFileName() ?: null,
                $reflection->getStartLine() ?: null,
            );
        }
    }

    /**
     * Get the type name if value cannot be exported, null if it can.
     */
    private function getUnexportableType(mixed $value): ?string
    {
        return match (true) {
            is_null($value),
            is_bool($value),
            is_int($value),
            is_float($value),
            is_string($value) => null, // Exportable

            is_array($value) => $this->getUnexportableArrayType($value),

            // Closures are NOT supported in inline mode - they would need
            // recursive export which is complex and error-prone
            $value instanceof Closure => 'Closure (nested closures not supported in inline mode)',

            is_object($value) => 'object (' . $value::class . ')',

            is_resource($value) => 'resource (' . get_resource_type($value) . ')',

            default => get_debug_type($value),
        };
    }

    /**
     * Check array elements and return type of first unexportable element.
     */
    private function getUnexportableArrayType(array $array): ?string
    {
        foreach ($array as $key => $value) {
            $type = $this->getUnexportableType($value);
            if ($type !== null) {
                return "array containing {$type} at key '{$key}'";
            }
        }

        return null;
    }
}
