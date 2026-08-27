<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Closure;
use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;
use stdClass;

it('rejects nested closures captured by value in Inline mode', function (): void {
    $nested = static fn(): int => 1;
    $closure = static fn(): Closure => $nested;

    expect(fn() => Export::closure($closure, new ExportConfig(closureUseMode: ClosureUseMode::Inline)))
        ->toThrow(ClosureExportException::class, 'nested Closure');
});

it('rejects object captures whose identity cannot be represented by Inline mode', function (): void {
    $object = new stdClass();
    $closure = static fn(): object => $object;

    expect(fn() => Export::closure($closure, new ExportConfig(closureUseMode: ClosureUseMode::Inline)))
        ->toThrow(ClosureExportException::class, 'object (stdClass)');
});

it('rejects live resource captures in Inline mode', function (): void {
    $resource = fopen('php://memory', 'rb');
    $closure = static fn() => $resource;

    try {
        expect(fn() => Export::closure($closure, new ExportConfig(closureUseMode: ClosureUseMode::Inline)))
            ->toThrow(ClosureExportException::class, 'resource (stream)');
    } finally {
        fclose($resource);
    }
});

it('rejects closed resource captures in Inline mode', function (): void {
    $resource = fopen('php://memory', 'rb');
    $closure = static fn() => $resource;
    fclose($resource);

    expect(fn() => Export::closure($closure, new ExportConfig(closureUseMode: ClosureUseMode::Inline)))
        ->toThrow(ClosureExportException::class, 'resource (closed)');
});

it('rejects runtime user-defined constants in PortableExpression mode', function (): void {
    if (!defined('COMPONENTA_VAR_EXPORT_RUNTIME_CONSTANT')) {
        define('COMPONENTA_VAR_EXPORT_RUNTIME_CONSTANT', 42);
    }

    $closure = static fn(): int => \COMPONENTA_VAR_EXPORT_RUNTIME_CONSTANT;
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);

    expect(fn() => Export::closure($closure, $config))
        ->toThrow(ClosureExportException::class, 'runtime user-defined constant');
});
