<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Closure;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;
use RuntimeException;
use stdClass;

final class LegacyClosureStrategy implements ClosureExporterInterface
{
    public function export(Closure $closure): string
    {
        return 'static fn (): int => 7';
    }

    public function exportWithDepth(Closure $closure, int $depth): string
    {
        return $this->export($closure);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self();
    }
}

final class LegacyObjectStrategy implements ObjectExporterInterface
{
    public function export(object $object): string
    {
        return 'new \\stdClass()';
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        return $this->export($object);
    }

    public function supports(object $object): bool
    {
        return true;
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self();
    }
}

final class ThrowingObjectStrategy implements ObjectExporterInterface
{
    public function export(object $object): string
    {
        throw new RuntimeException('custom object exporter failed');
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        return $this->export($object);
    }

    public function supports(object $object): bool
    {
        return true;
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self();
    }
}

final class LocationValueStrategy implements ContextualValueExporterInterface
{
    public function exportValue(mixed $value, ExportContext $context): string
    {
        return var_export($context->location(), true);
    }
}

final class ReplacementArrayStrategy implements ArrayExporterInterface
{
    public function export(array $array): string
    {
        return '[99]';
    }

    public function exportAtDepth(array $array, int $depth, string $baseIndent): string
    {
        return $this->export($array);
    }

    public function withConfig(ExportConfig $config): static
    {
        return $this;
    }
}

final readonly class ArrayProviderValue
{
    public function __construct(public mixed $value)
    {
    }
}

it('supports legacy low-level closure and object strategies through their public contracts', function (): void {
    $exporter = new ArrayExporter(
        closureExporter: new LegacyClosureStrategy(),
        objectExporter: new LegacyObjectStrategy(),
    );

    $code = $exporter->export([
        'closure' => static fn(): int => 1,
        'object' => new stdClass(),
    ]);
    $restored = eval('return ' . $code . ';');

    expect($restored['closure']())->toBe(7)
        ->and($restored['object'])->toBeInstanceOf(stdClass::class);
});

it('propagates semantic locations to a contextual value strategy', function (): void {
    $exporter = new ArrayExporter(valueExporter: new LocationValueStrategy());
    $restored = eval('return ' . $exporter->export(['a' => 1, 'b' => false]) . ';');

    expect($restored)->toBe([
        'a' => "\$value['a']",
        'b' => "\$value['b']",
    ]);
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

it('uses a custom public array strategy when reconstructing readonly object arguments', function (): void {
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

it('uses a supplied closure strategy at the public object-export boundary', function (): void {
    $exporter = new ObjectExporter(closureExporter: new LegacyClosureStrategy());
    $restored = eval('return ' . $exporter->export(static fn(): int => 1) . ';');

    expect($restored())->toBe(7);
});
