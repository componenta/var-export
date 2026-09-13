<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;

it('rejects an array pointer that a literal cannot preserve without changing the source', function (string $shape, int $steps): void {
    $values = ['first' => 'a', 'second' => 'b'];
    for ($step = 0; $step < $steps; ++$step) {
        next($values);
    }
    $captured = str_contains($shape, 'nested') ? ['items' => $values] : $values;
    $source = str_contains($shape, 'closure')
        ? static function () use ($captured): array {
            return $captured;
        }
    : $captured;

    expect(fn (): string => VarExport::withDefaults()->export($source))
        ->toThrow(ExportException::class, 'array pointer');
    expect(key($values))->toBe($steps === 1 ? 'second' : null)
        ->and(current($values))->toBe($steps === 1 ? 'b' : false);
})->with(['array', 'nested array', 'closure', 'nested closure'])->with([1, 2]);


it('preserves portable array pointers in values and closure captures', function (array $values, int|string|null $key, mixed $value): void {
    $exporter = VarExport::withDefaults();
    $restored = \Componenta\VarExport\Tests\Support\evaluateArrayExpression($exporter->export($values));
    $closure = static function () use ($values): array {
        return [key($values), current($values)];
    };
    $restoredClosure = \Componenta\VarExport\Tests\Support\evaluateClosureExpression($exporter->export($closure));

    expect(key($restored))->toBe($key)
        ->and(current($restored))->toBe($value)
        ->and($restoredClosure())->toBe([$key, $value]);
})->with([
    'empty array' => [[], null, false],
    'zero key with false' => [[false], 0, false],
    'negative key with null' => [[-5 => null], -5, null],
    'string key' => [['first' => 'a', 'second' => 'b'], 'first', 'a'],
]);
