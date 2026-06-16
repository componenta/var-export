<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

/*
 * Previously, inline mode happily inlined scalars captured by reference
 * (`use (&$x)`). The exported closure still *looked* correct but lost
 * the reference semantics - mutations no longer propagated to the
 * caller. That is worse than a loud failure: it ships a subtle bug.
 *
 * The fix bails out with ClosureExportException when a by-reference
 * capture reaches inline mode, so callers are told up front to either
 * drop the `&` or switch to ClosureUseMode::Preserve.
 */

it('throws when inline mode is asked to inline a by-reference use', function (): void {
    $counter = 0;
    $closure = static function () use (&$counter): void {
        $counter++;
    };

    $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);

    expect(fn () => Export::closure($closure, $config))
        ->toThrow(ClosureExportException::class, 'captured by reference');
});

it('still preserves the use clause for by-reference capture in preserve mode', function (): void {
    $value = 7;
    $closure = static function () use (&$value): int {
        return $value;
    };

    // Preserve mode is the documented path for by-reference captures:
    // the generated code must keep the `& use` so callers reconstruct
    // the closure with matching reference semantics.
    $code = Export::closure($closure, new ExportConfig(closureUseMode: ClosureUseMode::Preserve));

    expect($code)->toContain('use (&$value)');
});
