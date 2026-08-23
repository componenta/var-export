<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ObjectExporter;
use ReflectionClass;

final readonly class ContractSupportedValueObject { public function __construct(public int $n, public string $label) {} }
final readonly class ContractPrivateStateValueObject { public function __construct(private string $secret) {} }
final readonly class ContractHydratedValueObject
{
    public function __construct(public int $n)
    {
        if ($n < 0) { throw new \InvalidArgumentException('negative state is not constructor-valid'); }
    }
}

it('keeps generic readonly-object export disabled by default', function (): void {
    expect((new ObjectExporter())->supports(new ContractSupportedValueObject(42, 'ok')))->toBeFalse();
});

it('reports unsupported when state is not represented by public promoted properties', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    expect($exporter->supports(new ContractPrivateStateValueObject('hidden')))->toBeFalse();
});

it('reports supported for explicitly enabled constructor value objects', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    expect($exporter->supports(new ContractSupportedValueObject(42, 'ok')))->toBeTrue();
});

it('makes hydrated constructor replay an explicit caller risk rather than default support', function (): void {
    $reflection = new ReflectionClass(ContractHydratedValueObject::class);
    $object = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('n')->setValue($object, -1);
    expect((new ObjectExporter())->supports($object))->toBeFalse();
    $explicit = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    expect($explicit->supports($object))->toBeTrue();
    $code = $explicit->export($object);
    expect(fn() => eval('return ' . $code . ';'))->toThrow(\InvalidArgumentException::class);
});

it('reports unsupported for anonymous readonly classes', function (): void {
    $object = new readonly class (42) { public function __construct(public int $n) {} };
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    expect($exporter->supports($object))->toBeFalse();
});

it('reports unsupported for non-readonly classes', function (): void {
    $mutable = new class { public int $n = 1; };
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    expect($exporter->supports($mutable))->toBeFalse();
});

it('throws a structured error when nesting exceeds maxDepth', function (): void {
    $exporter = new ObjectExporter((new ExportConfig(maxDepth: 2))->withGenericReadonlyObjects());
    $leaf = new ContractSupportedValueObject(1, 'leaf');
    expect(fn() => $exporter->exportWithDepth($leaf, 10))->toThrow(ExportException::class, 'Maximum nesting depth');
});
