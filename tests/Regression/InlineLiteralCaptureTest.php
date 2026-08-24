<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

it('emits null and boolean Inline captures as PHP literals', function (): void {
    $null = null;
    $true = true;
    $false = false;
    $closure = static fn(): array => [$null, $true, $false];
    $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
    $code = Export::closure($closure, $config);
    $restored = eval('return ' . $code . ';');

    expect($code)->not->toContain('\\null')
        ->not->toContain('\\true')
        ->not->toContain('\\false')
        ->and($restored())->toBe([null, true, false]);
});
