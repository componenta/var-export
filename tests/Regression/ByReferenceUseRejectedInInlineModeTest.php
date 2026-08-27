<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects by-reference captures in Inline mode instead of losing alias semantics', function (): void {
    $counter = 0;
    $closure = static function () use (&$counter): void {
        ++$counter;
    };

    $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);

    expect(fn() => Export::closure($closure, $config))
        ->toThrow(ClosureExportException::class, 'captured by reference');
});

it('preserves live alias semantics for by-reference captures in Preserve mode', function (): void {
    $value = 7;
    $closure = static function () use (&$value): int {
        return $value;
    };
    $code = Export::closure(
        $closure,
        new ExportConfig(closureUseMode: ClosureUseMode::Preserve),
    );

    $value = 11;
    $restored = eval('return ' . $code . ';');
    expect($restored())->toBe(11);

    $value = 19;
    expect($restored())->toBe(19);
});
