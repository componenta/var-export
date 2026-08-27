<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

it('applies sortKeys recursively to arrays frozen into Inline captures', function (): void {
    $value = [
        'z' => 1,
        10 => 2,
        'a' => ['z' => 1, 5 => 2, 'a' => 3, 1 => 4],
        2 => 4,
    ];
    $closure = static fn(): array => $value;
    $config = new ExportConfig(sortKeys: true, closureUseMode: ClosureUseMode::Inline);

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($closure, $config) . ';');
    $result = $restored();

    expect(array_keys($result))->toBe([2, 10, 'a', 'z'])
        ->and(array_keys($result['a']))->toBe([1, 5, 'a', 'z']);
});

it('preserves Inline capture array order when sortKeys is disabled', function (): void {
    $value = ['z' => 1, 10 => 2, 'a' => 3, 2 => 4];
    $closure = static fn(): array => $value;
    $config = new ExportConfig(sortKeys: false, closureUseMode: ClosureUseMode::Inline);

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($closure, $config) . ';');

    expect(array_keys($restored()))->toBe(['z', 10, 'a', 2]);
});
