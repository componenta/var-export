<?php

declare(strict_types=1);

use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;

final readonly class ContextPropagationValue
{
    public function __construct(public int $first, public int $second) {}
}

it('preserves custom base indentation for contextual closures', function (): void {
    $closure = static function (): int {
        return 42;
    };
    $exporter = new ClosureExporter(ExportConfig::pretty()->withIndent('  '));
    $code = $exporter->exportWithContext($closure, new ExportContext(2, baseIndent: '>>'));

    expect($code)->toContain("\n>>  return 42;")
        ->and($code)->toEndWith("\n>>}");
});

it('preserves custom base indentation for contextual objects', function (): void {
    $config = ExportConfig::pretty()->withIndent('  ')->withGenericReadonlyObjects();
    $exporter = new ObjectExporter($config);
    $code = $exporter->exportWithContext(
        new ContextPropagationValue(1, 2),
        new ExportContext(1, baseIndent: '>>'),
    );

    expect($code)->toContain("\n>>  1,")
        ->and($code)->toContain("\n>>  2,")
        ->and($code)->toEndWith("\n>>)");
});

it('applies maxDepth to primitive object arguments in standalone mode', function (): void {
    $config = (new ExportConfig(maxDepth: 1))->withGenericReadonlyObjects();
    $exporter = new ObjectExporter($config);

    expect(fn() => $exporter->exportWithContext(
        new ContextPropagationValue(1, 2),
        new ExportContext(1, baseIndent: ''),
    ))->toThrow(ExportException::class, 'Maximum nesting depth');
});
