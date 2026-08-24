<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects nested class-like declarations instead of rewriting their lexical magic context', function (): void {
    $closure = static fn(): object => new class {
        public function className(): string
        {
            return __CLASS__;
        }
    };

    expect(fn() => Export::closure($closure))
        ->toThrow(ClosureExportException::class, 'nested class-like declaration');
});

it('rejects nested named function declarations whose namespace identity cannot be reproduced', function (): void {
    $closure = static function (): void {
        function componenta_var_export_nested_declaration_fixture(): string
        {
            return __FUNCTION__;
        }
    };

    expect(fn() => Export::closure($closure))
        ->toThrow(ClosureExportException::class, 'nested named function declaration');
});
