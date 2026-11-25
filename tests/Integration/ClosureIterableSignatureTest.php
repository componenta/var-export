<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use ArrayIterator;
use Componenta\VarExport\VarExport;
use TypeError;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('preserves iterable aliases in standalone nullable and union signatures', function (string $signature, mixed $extra): void {
    $path = createTemporaryPhpFile(
        'componenta-iterable-signature-',
        '<?php declare(strict_types=1); return static fn (' . $signature . ' $value): ' . $signature . ' => $value;',
    );

    try {
        $original = requireClosureFile($path);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));
        $iterator = new ArrayIterator([1, 2]);

        expect($restored([1, 2]))->toBe([1, 2])
            ->and($restored($iterator))->toBe($iterator)
            ->and(fn (): mixed => $restored(1.5))->toThrow(TypeError::class);

        if ($signature !== 'iterable') {
            expect($restored($extra))->toBe($extra);
        }
    } finally {
        unlink($path);
    }
})->with([
    'standalone iterable' => ['iterable', null],
    'nullable iterable' => ['?iterable', null],
    'iterable union' => ['iterable|string', 'value'],
    'iterable null union' => ['iterable|null', null],
    'iterable scalar and null union' => ['iterable|int|null', 42],
]);
