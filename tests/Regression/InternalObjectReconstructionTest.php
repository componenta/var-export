<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Export;

it('rejects internal extension objects from generic constructor reconstruction', function (): void {
    $config = (new ExportConfig())->withGenericReadonlyObjects();

    expect(fn() => Export::var(new DateTimeImmutable('2026-08-24T18:00:00+00:00'), $config))
        ->toThrow(ExportException::class, 'Internal/extension class');
});
