<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ObjectExporter;

/*
 * Two regressions for ObjectExporter:
 *
 * 1. supports() used to return `true` for every readonly class, even when
 *    the class had no public properties to round-trip through its
 *    constructor. That lied to callers: they would get `true` from the
 *    predicate and then an ExportException from export() moments later.
 *    supports() must honestly reflect whether export() can succeed.
 *
 * 2. exportWithDepth() had no max-depth guard, so two readonly objects
 *    pointing at each other would blow the stack rather than fail with
 *    a structured error like the array path already did.
 */

it('reports unsupported for readonly classes without matching public props', function (): void {
    // Constructor parameter $secret has no corresponding public property -
    // round-tripping via `new Foo(...)` cannot reconstruct this object,
    // so supports() must say so up front.
    $object = new readonly class ('hidden') {
        public function __construct(private string $secret) {}
    };

    expect((new ObjectExporter())->supports($object))->toBeFalse();
});

it('reports supported when every constructor parameter is a public prop', function (): void {
    $object = new readonly class (42, 'ok') {
        public function __construct(public int $n, public string $label) {}
    };

    expect((new ObjectExporter())->supports($object))->toBeTrue();
});

it('reports unsupported for non-readonly classes', function (): void {
    $mutable = new class {
        public int $n = 1;
    };

    expect((new ObjectExporter())->supports($mutable))->toBeFalse();
});

it('throws a structured error when nesting exceeds maxDepth', function (): void {
    $exporter = new ObjectExporter(new ExportConfig(maxDepth: 2));

    $leaf = new readonly class ('leaf') {
        public function __construct(public string $name) {}
    };

    // Synthesize a depth that exceeds the configured limit by passing a
    // depth marker straight into exportWithDepth(). This mirrors what
    // happens inside a runaway recursion without having to actually
    // build cyclic readonly objects (which PHP refuses to instantiate).
    expect(fn () => $exporter->exportWithDepth($leaf, 10))
        ->toThrow(ExportException::class, 'Maximum nesting depth');
});
