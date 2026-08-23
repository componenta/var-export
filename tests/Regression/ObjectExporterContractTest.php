<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ObjectExporter;

final readonly class ContractSupportedValueObject
{
    public function __construct(public int $n, public string $label)
    {
    }
}

final readonly class ContractPrivateStateValueObject
{
    public function __construct(private string $secret)
    {
    }
}

it('reports unsupported when state is not represented by public promoted properties', function (): void {
    expect((new ObjectExporter())->supports(new ContractPrivateStateValueObject('hidden')))->toBeFalse();
});

it('reports supported for named readonly promoted value objects', function (): void {
    expect((new ObjectExporter())->supports(new ContractSupportedValueObject(42, 'ok')))->toBeTrue();
});

it('reports unsupported for anonymous readonly classes', function (): void {
    $object = new readonly class (42) {
        public function __construct(public int $n)
        {
        }
    };

    expect((new ObjectExporter())->supports($object))->toBeFalse();
});

it('reports unsupported for non-readonly classes', function (): void {
    $mutable = new class {
        public int $n = 1;
    };

    expect((new ObjectExporter())->supports($mutable))->toBeFalse();
});

it('throws a structured error when nesting exceeds maxDepth', function (): void {
    $exporter = new ObjectExporter(new ExportConfig(maxDepth: 2));
    $leaf = new ContractSupportedValueObject(1, 'leaf');

    expect(fn() => $exporter->exportWithDepth($leaf, 10))
        ->toThrow(ExportException::class, 'Maximum nesting depth');
});
