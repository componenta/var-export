<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Closure;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ObjectExporter;
use RuntimeException;
use stdClass;

final class CustomClosureStrategy implements ClosureExporterInterface
{
    public function export(Closure $closure): string
    {
        return 'static fn (): int => 7';
    }
}

final class CustomObjectStrategy implements ObjectExporterInterface
{
    public function export(object $object): string
    {
        return 'new \\stdClass()';
    }
}

final class ThrowingObjectStrategy implements ObjectExporterInterface
{
    public function export(object $object): string
    {
        throw new RuntimeException('custom object exporter failed');
    }
}

final class ReplacementArrayStrategy implements ArrayExporterInterface
{
    public function export(array $array): string
    {
        return '[99]';
    }
}

final readonly class ArrayProviderValue
{
    public function __construct(public mixed $value)
    {
    }
}

it('uses minimal closure and object strategy contracts', function (): void {
    $exporter = new ArrayExporter(
        closureExporter: new CustomClosureStrategy(),
        objectExporter: new CustomObjectStrategy(),
    );

    $code = $exporter->export([
        'closure' => static fn(): int => 1,
        'object' => new stdClass(),
    ]);
    $restored = eval('return ' . $code . ';');

    expect($restored['closure']())->toBe(7)
        ->and($restored['object'])->toBeInstanceOf(stdClass::class);
});

it('reports stream and closed-resource array elements through the public array boundary', function (): void {
    $resource = fopen('php://memory', 'rb');

    try {
        expect(fn() => (new ArrayExporter())->export(['resource' => $resource]))
            ->toThrow(ArrayExportException::class, 'resource (stream)');
    } finally {
        fclose($resource);
    }

    expect(fn() => (new ArrayExporter())->export(['resource' => $resource]))
        ->toThrow(ArrayExportException::class, 'resource (closed)');
});

it('retains a custom object-strategy failure as the previous exception', function (): void {
    try {
        (new ArrayExporter(objectExporter: new ThrowingObjectStrategy()))
            ->export(['object' => new stdClass()]);
        test()->fail('Expected array export to fail.');
    } catch (ArrayExportException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('custom object exporter failed');
    }
});

it('uses a custom array strategy when reconstructing readonly object arguments', function (): void {
    $config = (new ExportConfig())->withGenericReadonlyObjects();
    $exporter = new ObjectExporter(
        $config,
        arrayExporterProvider: static fn(): ArrayExporterInterface => new ReplacementArrayStrategy(),
    );

    $code = $exporter->export(new ArrayProviderValue([1, 2]));
    $restored = eval('return ' . $code . ';');

    expect($restored)->toEqual(new ArrayProviderValue([99]));
});

it('rejects an invalid custom array-strategy provider with a typed export failure', function (): void {
    $config = (new ExportConfig())->withGenericReadonlyObjects();
    $exporter = new ObjectExporter(
        $config,
        arrayExporterProvider: static fn(): object => new stdClass(),
    );

    expect(fn() => $exporter->export(new ArrayProviderValue([1])))
        ->toThrow(ExportException::class, 'array provider must return');
});

it('uses a supplied closure strategy at the object-export boundary', function (): void {
    $exporter = new ObjectExporter(closureExporter: new CustomClosureStrategy());
    $restored = eval('return ' . $exporter->export(static fn(): int => 1) . ';');

    expect($restored())->toBe(7);
});
