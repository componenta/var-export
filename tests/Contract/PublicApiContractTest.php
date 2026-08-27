<?php

declare(strict_types=1);

use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Contract\ContextualClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Contract\VarExporterInterface;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;
use Componenta\VarExport\VarExporter;

it('keeps VarExporter focused on exporting one value', function (): void {
    $methods = ownPublicMethods(VarExporter::class);

    expect($methods)->toBe(['__construct', 'export']);
});

it('keeps ClosureExporter focused on exporting one closure', function (): void {
    $methods = ownPublicMethods(ClosureExporter::class);

    expect($methods)->toBe(['__construct', 'export']);
});

it('keeps the object strategy contract to one operation', function (): void {
    $methods = array_map(
        static fn(\ReflectionMethod $method): string => $method->getName(),
        (new \ReflectionClass(ObjectExporterInterface::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
    );
    sort($methods);

    expect($methods)->toBe(['export']);
});

it('does not expose implementation decomposition as public contracts', function (): void {
    foreach ([
        VarExporterInterface::class,
        ArrayExporterInterface::class,
        ClosureExporterInterface::class,
        ValueFormatterInterface::class,
        ContextualValueExporterInterface::class,
        ContextualClosureExporterInterface::class,
        ContextualObjectExporterInterface::class,
        ClosureSourceCacheInterface::class,
    ] as $contract) {
        expect(interface_exists($contract))->toBeFalse();
    }
});

it('does not expose array, generic-object, or context helpers as root classes', function (): void {
    foreach ([
        ArrayExporter::class,
        ObjectExporter::class,
        ExportContext::class,
    ] as $class) {
        expect(class_exists($class))->toBeFalse();
    }
});

/** @param class-string $class */
function ownPublicMethods(string $class): array
{
    $reflection = new \ReflectionClass($class);
    $methods = array_map(
        static fn(\ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class,
        ),
    );
    sort($methods);

    return $methods;
}
