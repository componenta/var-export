<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

enum InlineCaptureStatus: string
{
    case Ready = 'ready';
}

it('freezes enum cases in Inline captures without generic object reconstruction', function (): void {
    $status = InlineCaptureStatus::Ready;
    $closure = static fn(): InlineCaptureStatus => $status;
    $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
    $restored = eval('return ' . Export::closure($closure, $config) . ';');

    expect($restored())->toBe(InlineCaptureStatus::Ready);
});
