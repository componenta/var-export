<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\VarExporter;

it('enforces maxDepth when used through the public contextual value-export contract', function (): void {
    $exporter = new VarExporter(new ExportConfig(maxDepth: 1));
    $context = new ExportContext(depth: 2, path: ['nested']);

    try {
        $exporter->exportValue('value', $context);
        test()->fail('Expected contextual export to exceed maxDepth.');
    } catch (ExportException $exception) {
        expect($exception->getMessage())->toContain("at \$value['nested']")
            ->and($exception->context['max_depth'] ?? null)->toBe(1)
            ->and($exception->context['depth'] ?? null)->toBe(2)
            ->and($exception->context['path'] ?? null)->toBe(['nested']);
    }
});
