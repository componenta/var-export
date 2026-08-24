<?php

declare(strict_types=1);

use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;

final readonly class ValidContextPropagationValue
{
    public function __construct(public int $first, public int $second) {}
}

it('preserves whitespace base indentation for contextual closures', function (): void {
    $closure = static function (): int {
        return 42;
    };
    $exporter = new ClosureExporter(ExportConfig::pretty()->withIndent('  '));
    $baseIndent = "\t  ";
    $code = $exporter->exportWithContext($closure, new ExportContext(2, baseIndent: $baseIndent));

    expect($code)->toContain("\n{$baseIndent}  return 42;")
        ->and($code)->toEndWith("\n{$baseIndent}}");
});

it('preserves whitespace base indentation for contextual objects', function (): void {
    $config = ExportConfig::pretty()->withIndent('  ')->withGenericReadonlyObjects();
    $exporter = new ObjectExporter($config);
    $baseIndent = "\t  ";
    $code = $exporter->exportWithContext(
        new ValidContextPropagationValue(1, 2),
        new ExportContext(1, baseIndent: $baseIndent),
    );

    expect($code)->toContain("\n{$baseIndent}  1,")
        ->and($code)->toContain("\n{$baseIndent}  2,")
        ->and($code)->toEndWith("\n{$baseIndent})");
});

it('rejects non-whitespace base indentation', function (): void {
    expect(fn() => new ExportContext(baseIndent: '>>'))
        ->toThrow(InvalidArgumentException::class, 'spaces and tabs');
});

it('applies maxDepth to primitive object arguments in standalone mode', function (): void {
    $config = (new ExportConfig(maxDepth: 1))->withGenericReadonlyObjects();
    $exporter = new ObjectExporter($config);

    expect(fn() => $exporter->exportWithContext(
        new ValidContextPropagationValue(1, 2),
        new ExportContext(1, baseIndent: ''),
    ))->toThrow(ExportException::class, 'Maximum nesting depth');
});
