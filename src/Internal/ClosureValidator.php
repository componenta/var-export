<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Closure;
use Componenta\VarExport\Exception\ClosureExportException;
use ReflectionFunction;

/** @internal */
final readonly class ClosureValidator
{
    /** @throws ClosureExportException */
    public function validate(Closure $closure): ReflectionFunction
    {
        $reflection = new ReflectionFunction($closure);
        $filename = $reflection->getFileName();

        if ($filename === false || !is_file($filename)) {
            throw ClosureExportException::sourceNotFound($filename ?: 'unknown');
        }
        if (!is_readable($filename)) {
            throw ClosureExportException::sourceUnreadable($filename);
        }
        if ($reflection->getClosureThis() !== null) {
            throw ClosureExportException::boundThis($reflection);
        }

        $scope = $reflection->getClosureScopeClass();
        if ($scope === null) {
            return $reflection;
        }
        if ($scope->isAnonymous()) {
            throw ClosureExportException::nonPortableScope($reflection, 'anonymous class scope is not addressable in generated source');
        }

        $called = $reflection->getClosureCalledClass() ?? $scope;
        if ($called->getName() !== $scope->getName()) {
            throw ClosureExportException::nonPortableScope(
                $reflection,
                sprintf(
                    'different lexical and called classes (lexical class %s vs called class %s); late-static-binding state cannot be reconstructed exactly',
                    $scope->getName(),
                    $called->getName(),
                ),
            );
        }

        return $reflection;
    }
}
