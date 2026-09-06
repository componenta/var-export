<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Support;

use Closure;
use RuntimeException;

function classOrInterfaceExists(string $class): bool
{
    return class_exists($class) || interface_exists($class);
}

function functionSymbolExists(string $function): bool
{
    return function_exists($function);
}

function requireExpression(?string $expression): string
{
    if ($expression === null) {
        throw new RuntimeException('Expected the exporter to return an expression.');
    }

    return $expression;
}

function evaluateExpression(string $expression): mixed
{
    return eval('declare(strict_types=1); return ' . $expression . ';');
}

function evaluateClosureExpression(string $expression): Closure
{
    $value = evaluateExpression($expression);
    if (!$value instanceof Closure) {
        throw new RuntimeException('Expected the expression to evaluate to a Closure.');
    }

    return $value;
}

/** @return array<mixed> */
function evaluateArrayExpression(string $expression): array
{
    $value = evaluateExpression($expression);
    if (!is_array($value)) {
        throw new RuntimeException('Expected the expression to evaluate to an array.');
    }

    return $value;
}

function evaluateFloatExpression(string $expression): float
{
    $value = evaluateExpression($expression);
    if (!is_float($value)) {
        throw new RuntimeException('Expected the expression to evaluate to a float.');
    }

    return $value;
}

function createTemporaryPhpFile(string $prefix, string $source): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary PHP file.');
    }

    overwriteFile($path, $source);

    return $path;
}

function overwriteFile(string $path, string $source): void
{
    if (file_put_contents($path, $source) === false) {
        throw new RuntimeException(sprintf('Unable to write temporary PHP file "%s".', $path));
    }
}

function requireClosureFile(string $path): Closure
{
    $value = require $path;
    if (!$value instanceof Closure) {
        throw new RuntimeException(sprintf('Expected "%s" to return a Closure.', $path));
    }

    return $value;
}

/** @return resource */
function openMemoryStream()
{
    $resource = fopen('php://memory', 'rb');
    if ($resource === false) {
        throw new RuntimeException('Unable to open an in-memory stream.');
    }

    return $resource;
}
