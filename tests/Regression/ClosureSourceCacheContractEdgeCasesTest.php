<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Source\ClosureSourceCache;
use ReflectionFunction;

it('returns no candidates for an invalid declaration line without touching the filesystem', function (): void {
    $cache = new ClosureSourceCache();

    expect($cache->candidates('/definitely/missing/source.php', 0))->toBe([])
        ->and($cache->size())->toBe(0);
});

it('reports a missing source file through the public cache contract', function (): void {
    $path = sys_get_temp_dir() . '/componenta-var-export-missing-' . bin2hex(random_bytes(8)) . '.php';
    $cache = new ClosureSourceCache();

    try {
        $cache->candidates($path, 1);
        test()->fail('Expected missing closure source to fail.');
    } catch (ClosureExportException $exception) {
        expect($exception->getMessage())->toContain('Cannot locate closure source file')
            ->and($exception->context['filename'] ?? null)->toBe($path);
    }
});

it('normalizes parser failures and retains the parser exception', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'componenta-var-export-invalid-');
    expect($path)->toBeString();
    file_put_contents($path, "<?php\nstatic fn( => 1;\n");

    try {
        (new ClosureSourceCache())->candidates($path, 2);
        test()->fail('Expected malformed closure source to fail.');
    } catch (ClosureExportException $exception) {
        expect($exception->getMessage())->toContain('Failed to parse closure source file')
            ->and($exception->getPrevious())->not->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('supports an unbounded per-source read limit without changing candidate semantics', function (): void {
    $cache = new ClosureSourceCache(maxSourceBytes: PHP_INT_MAX);
    $reflection = new ReflectionFunction(static fn(): int => 1);
    $filename = $reflection->getFileName();
    $line = $reflection->getStartLine();

    expect($filename)->toBeString()
        ->and($line)->toBeInt()
        ->and($cache->candidates($filename, $line))->toHaveCount(1);
});
