<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Source\ClosureSourceCache;
use Componenta\VarExport\VarExporter;

it('keeps using a supplied public source cache after withConfig()', function (): void {
    $cache = new ClosureSourceCache();
    $exporter = new VarExporter(sourceCache: $cache);
    $closure = static fn(int $x): int => $x + 1;

    $exporter->export($closure);
    expect($cache->size())->toBe(1);

    $cache->clear();
    expect($cache->size())->toBe(0);

    $reconfigured = $exporter->withConfig(ExportConfig::pretty());
    $restored = eval('return ' . $reconfigured->export($closure) . ';');

    expect($restored(4))->toBe(5)
        ->and($cache->size())->toBe(1);
});
