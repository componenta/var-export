<?php

declare(strict_types=1);

use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Contract\ContextualClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Contract\ValueFormatterInterface;
use Componenta\VarExport\Contract\VarExporterInterface;
use Componenta\VarExport\VarExporter;

it('keeps VarExporter focused on exporting one value', function (): void {
    $reflection = new \ReflectionClass(VarExporter::class);
    $methods = array_map(
        static fn(\ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === VarExporter::class,
        ),
    );

    sort($methods);

    expect($methods)->toBe(['__construct', 'export']);
});

it('keeps exporter contracts limited to their single operation', function (): void {
    $contracts = [
        ArrayExporterInterface::class => ['export'],
        ClosureExporterInterface::class => ['export'],
        ObjectExporterInterface::class => ['export'],
        ValueFormatterInterface::class => ['format'],
    ];

    foreach ($contracts as $contract => $expectedMethods) {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($contract))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        expect($methods)->toBe($expectedMethods);
    }
});

it('does not expose internal orchestration as contracts', function (): void {
    foreach ([
        VarExporterInterface::class,
        ContextualValueExporterInterface::class,
        ContextualClosureExporterInterface::class,
        ContextualObjectExporterInterface::class,
        ClosureSourceCacheInterface::class,
    ] as $contract) {
        expect(interface_exists($contract))->toBeFalse();
    }
});
