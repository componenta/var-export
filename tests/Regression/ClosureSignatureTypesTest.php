<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use ArrayIterator;
use Componenta\VarExport\Export;
use Countable;
use Iterator;

final class ClosureSignatureDefaultFixture
{
    public const int VALUE = 7;
}

it('round-trips nullable closure types and a null default', function (): void {
    $closure = static fn(?int $value = null): ?int => $value;
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBeNull()
        ->and($restored(4))->toBe(4);
});

it('round-trips union types with a class-constant default', function (): void {
    $closure = static fn(int|string $value = ClosureSignatureDefaultFixture::VALUE): int|string => $value;
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBe(ClosureSignatureDefaultFixture::VALUE)
        ->and($restored('value'))->toBe('value');
});

it('round-trips a global-constant parameter default', function (): void {
    $closure = static fn(int $value = PHP_INT_MAX): int => $value;
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBe(PHP_INT_MAX);
});

it('round-trips intersection parameter and return types', function (): void {
    $closure = static fn(Iterator&Countable $value): Iterator&Countable => $value;
    $restored = eval('return ' . Export::closure($closure) . ';');
    $iterator = new ArrayIterator([1, 2]);

    expect($restored($iterator))->toBe($iterator);
});

it('round-trips DNF parameter and return types', function (): void {
    $closure = static fn((Iterator&Countable)|array $value): (Iterator&Countable)|array => $value;
    $restored = eval('return ' . Export::closure($closure) . ';');
    $iterator = new ArrayIterator([1, 2]);

    expect($restored($iterator))->toBe($iterator)
        ->and($restored(['value']))->toBe(['value']);
});
