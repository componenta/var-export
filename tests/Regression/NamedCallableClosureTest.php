<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

function componenta_var_export_named_callable_fixture(int $value): int
{
    return $value * 2;
}

it('rejects closures created from named callables before anonymous AST selection', function (): void {
    $closure = Closure::fromCallable('componenta_var_export_named_callable_fixture');

    expect(fn() => Export::closure($closure))
        ->toThrow(ClosureExportException::class, 'named callable');
});
