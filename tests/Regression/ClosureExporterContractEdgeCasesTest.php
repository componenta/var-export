<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\Source\ClosureSourceCache;
use Componenta\VarExport\Source\ClosureSourceCandidate;
use RuntimeException;

final class EmptyClosureSourceCache implements ClosureSourceCacheInterface
{
    public function candidates(string $filename, int $startLine): array
    {
        return [];
    }

    public function clear(): void
    {
    }

    public function size(): int
    {
        return 0;
    }
}

final class ThrowingClosureSourceCache implements ClosureSourceCacheInterface
{
    public function candidates(string $filename, int $startLine): array
    {
        throw new RuntimeException('source cache failed');
    }

    public function clear(): void
    {
    }

    public function size(): int
    {
        return 0;
    }
}

final class DuplicatingClosureSourceCache implements ClosureSourceCacheInterface
{
    public function __construct(private ClosureSourceCache $delegate = new ClosureSourceCache())
    {
    }

    public function candidates(string $filename, int $startLine): array
    {
        $candidates = $this->delegate->candidates($filename, $startLine);
        if ($candidates === []) {
            return [];
        }

        /** @var ClosureSourceCandidate $candidate */
        $candidate = $candidates[0];

        return [$candidate, $candidate->detached()];
    }

    public function clear(): void
    {
        $this->delegate->clear();
    }

    public function size(): int
    {
        return $this->delegate->size();
    }
}

it('rejects negative standalone closure depth at the public exporter boundary', function (): void {
    $closure = static fn(): int => 1;

    expect(fn() => (new ClosureExporter())->exportWithDepth($closure, -1))
        ->toThrow(ClosureExportException::class, 'must be non-negative');
});

it('rejects a contextual depth beyond maxDepth before reading source', function (): void {
    $closure = static fn(): int => 1;
    $exporter = new ClosureExporter(new ExportConfig(maxDepth: 1), new ThrowingClosureSourceCache());

    expect(fn() => $exporter->exportWithContext($closure, new ExportContext(depth: 2)))
        ->toThrow(ClosureExportException::class, 'exceeds maxDepth');
});

it('reports a missing AST candidate returned by a custom public source-cache strategy', function (): void {
    $closure = static fn(): int => 1;
    $exporter = new ClosureExporter(sourceCache: new EmptyClosureSourceCache());

    expect(fn() => $exporter->export($closure))
        ->toThrow(ClosureExportException::class, 'Cannot locate closure AST node');
});

it('normalizes unexpected source-cache failures and retains the previous exception', function (): void {
    $closure = static fn(): int => 1;
    $exporter = new ClosureExporter(sourceCache: new ThrowingClosureSourceCache());

    try {
        $exporter->export($closure);
        test()->fail('Expected closure export to fail.');
    } catch (ClosureExportException $exception) {
        expect($exception->getMessage())->toContain('Closure export failed: source cache failed')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

it('rejects indistinguishable source candidates supplied by the cache contract', function (): void {
    $closure = static fn(int $value): int => $value + 1;
    $exporter = new ClosureExporter(sourceCache: new DuplicatingClosureSourceCache());

    expect(fn() => $exporter->export($closure))
        ->toThrow(ClosureExportException::class, 'indistinguishable closure candidates');
});
