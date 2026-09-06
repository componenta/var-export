<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Architecture;

use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\Exception\ExceptionInterface;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\VarExport;
use Componenta\VarExport\VarExportAwareInterface;
use Componenta\VarExport\VarExportInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function Componenta\VarExport\Tests\Support\classOrInterfaceExists;
use function Componenta\VarExport\Tests\Support\functionSymbolExists;

it('keeps orchestration, exporting and awareness as independent public contracts', function (): void {
    $orchestrator = new ReflectionClass(VarExport::class);
    $awareness = new ReflectionClass(VarExportAwareInterface::class);

    expect($orchestrator->implementsInterface(VarExportInterface::class))->toBeFalse()
        ->and($awareness->implementsInterface(VarExportInterface::class))->toBeFalse()
        ->and($orchestrator->isFinal())->toBeTrue();
});

it('exposes the library exception marker as a Throwable contract', function (): void {
    expect((new ReflectionClass(ExceptionInterface::class))->getInterfaceNames())
        ->toContain(\Throwable::class);
});

it('exposes four default exporter capabilities and a zero-argument factory', function (): void {
    expect(new ScalarExporter())->toBeInstanceOf(VarExportInterface::class)
        ->and(new EnumExporter())->toBeInstanceOf(VarExportInterface::class)
        ->and(new ArrayExporter())->toBeInstanceOf(VarExportInterface::class)
        ->toBeInstanceOf(VarExportAwareInterface::class)
        ->and(new ClosureExporter())->toBeInstanceOf(VarExportInterface::class)
        ->toBeInstanceOf(VarExportAwareInterface::class)
        ->and(new ReflectionMethod(VarExport::class, 'withDefaults')->getNumberOfParameters())->toBe(0);
});

it('declares the agreed constructor, factory and exporter method signatures', function (): void {
    $constructor = new ReflectionMethod(VarExport::class, '__construct');
    $factory = new ReflectionMethod(VarExport::class, 'withDefaults');
    $orchestratorExport = new ReflectionMethod(VarExport::class, 'export');
    $exportContract = new ReflectionMethod(VarExportInterface::class, 'export');
    $awareContract = new ReflectionMethod(VarExportAwareInterface::class, 'withVarExport');
    $constructorParameter = $constructor->getParameters()[0];
    $orchestratorParameter = $orchestratorExport->getParameters()[0];
    $exportParameter = $exportContract->getParameters()[0];
    $awareParameter = $awareContract->getParameters()[0];
    $factoryReturnType = $factory->getReturnType();
    if (!$factoryReturnType instanceof ReflectionNamedType) {
        throw new \LogicException('Expected VarExport::withDefaults() to declare a named return type.');
    }

    $factoryReturnName = $factoryReturnType->getName() === 'self'
        ? $factory->getDeclaringClass()->getName()
        : ltrim($factoryReturnType->getName(), '\\');

    expect($constructor->getNumberOfParameters())->toBe(1)
        ->and($constructorParameter->getName())->toBe('exporters')
        ->and($constructorParameter->isVariadic())->toBeTrue()
        ->and((string) $constructorParameter->getType())->toBe(VarExportInterface::class)
        ->and($factoryReturnName)->toBe(VarExport::class)
        ->and($orchestratorParameter->getName())->toBe('var')
        ->and((string) $orchestratorParameter->getType())->toBe('mixed')
        ->and((string) $orchestratorExport->getReturnType())->toBe('string')
        ->and($exportParameter->getName())->toBe('var')
        ->and((string) $exportParameter->getType())->toBe('mixed')
        ->and((string) $exportContract->getReturnType())->toBe('?string')
        ->and($awareParameter->getName())->toBe('varExport')
        ->and((string) $awareParameter->getType())->toBe(VarExport::class)
        ->and((string) $awareContract->getReturnType())->toBe('static');
});

it('does not expose the removed legacy API', function (): void {
    foreach ([
        'Componenta\\VarExport\\VarExporter',
        'Componenta\\VarExport\\Export',
        'Componenta\\VarExport\\ExportConfig',
        'Componenta\\VarExport\\Config\\ExportConfig',
        'Componenta\\VarExport\\ExportContext',
        'Componenta\\VarExport\\Contract\\ObjectExporterInterface',
    ] as $legacyClass) {
        expect(classOrInterfaceExists($legacyClass))->toBeFalse();
    }

    foreach ([
        'Componenta\\VarExport\\var_export_string',
        'Componenta\\VarExport\\var_export_pretty',
        'Componenta\\VarExport\\array_export',
        'Componenta\\VarExport\\closure_export',
    ] as $legacyFunction) {
        expect(functionSymbolExists($legacyFunction))->toBeFalse();
    }
});
