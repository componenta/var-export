<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Internal\AstCache;
use Componenta\VarExport\VarExporter;

/*
 * Regression for VarExporter::withConfig(), which previously dropped the
 * shared AstCache and spun up a brand-new one on every reconfiguration.
 * That silently defeated the "reuse the exporter to benefit from caching"
 * story documented in the README.
 *
 * The observable behavior to guard: an externally supplied AstCache must
 * remain the cache in use after withConfig(). We exercise that by clearing
 * the cache through its external handle between two exports - if the
 * reconfigured exporter secretly held a detached cache, the clear would
 * be invisible to it and the external handle would stay at size 0 after
 * the second export.
 */

it('keeps using the externally supplied AstCache after withConfig()', function (): void {
    $cache = new AstCache();
    $original = new VarExporter(new ExportConfig(), $cache);
    $reconfigured = $original->withConfig(ExportConfig::pretty());

    $closure = static fn(int $x): int => $x + 1;

    // Seed the external cache via the original exporter.
    $original->export($closure);
    expect($cache->size())->toBe(1);

    // Clear the cache through its external handle. If withConfig() had
    // dropped the cache, the reconfigured exporter would be holding a
    // detached instance and this clear would be invisible to it.
    $cache->clear();
    expect($cache->size())->toBe(0);

    // Exporting via the reconfigured copy must register in the SAME
    // external cache, proving the shared reference was preserved.
    $reconfigured->export($closure);
    expect($cache->size())->toBe(1);
});
