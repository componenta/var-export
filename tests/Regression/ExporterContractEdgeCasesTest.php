<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\VarExporter;
use stdClass;

final class CustomObjectStrategy implements ObjectExporterInterface
{
    public function export(object $object): string
    {
        return 'new \\stdClass()';
    }
}

it('uses the object strategy contract for nested objects', function (): void {
    $exporter = new VarExporter(objectExporter: new CustomObjectStrategy());
    $restored = eval('return ' . $exporter->export(['object' => new stdClass()]) . ';');

    expect($restored['object'])->toBeInstanceOf(stdClass::class);
});
