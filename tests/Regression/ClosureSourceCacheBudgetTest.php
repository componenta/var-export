<?php

declare(strict_types=1);

use Componenta\VarExport\Source\ClosureSourceCache;

it('does not evict retained entries for a source too large for the aggregate cache budget', function (): void {
    $small = sys_get_temp_dir() . '/componenta_cache_small_' . bin2hex(random_bytes(6)) . '.php';
    $large = sys_get_temp_dir() . '/componenta_cache_large_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents($small, '<?php return static fn(): int => 1;');
        file_put_contents(
            $large,
            '<?php /*' . str_repeat('x', 160) . '*/ return static fn(): int => 2;',
        );

        $cache = new ClosureSourceCache(
            maxEntries: 4,
            maxSourceBytes: 512,
            maxCachedSourceBytes: 100,
        );

        expect($cache->candidates($small, 1))->toHaveCount(1)
            ->and($cache->size())->toBe(1)
            ->and($cache->candidates($large, 1))->toHaveCount(1)
            ->and($cache->size())->toBe(1)
            ->and($cache->candidates($small, 1))->toHaveCount(1)
            ->and($cache->size())->toBe(1);
    } finally {
        @unlink($small);
        @unlink($large);
    }
});
