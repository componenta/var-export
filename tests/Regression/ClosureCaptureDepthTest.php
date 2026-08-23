<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\VarExporter;

it('counts inline capture depth from the surrounding exported value', function (): void {
    $captured = ['nested' => ['too-deep']];
    $closure = static fn(): array => $captured;
    $config = (new ExportConfig(maxDepth: 2))
        ->withClosureUseMode(ClosureUseMode::Inline);

    expect(fn() => (new VarExporter($config))->export(['callback' => $closure]))
        ->toThrow(ClosureExportException::class, 'maxDepth');
});
