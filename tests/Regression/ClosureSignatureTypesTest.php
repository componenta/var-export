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

it('round-trips nullable union intersection and DNF closure signatures with constant defaults', function (): void {
    $nullable = static fn(?int $value = null): ?int => $value;
    $union = static fn(int|string $value = ClosureSignatureDefaultFixture::VALUE): int|string => $value;
    $globalConstant = static fn(int $value = PHP_INT_MAX): int => $value;
    $intersection = static fn(Iterator&Countable $value): Iterator&Countable => $value;
    $dnf = static fn((Iterator&Countable)|array $value): (Iterator&Countable)|array => $value;

    $nullableRestored = eval('return ' . Export::closure($nullable) . ';');
    $unionRestored = eval('return ' . Export::closure($union) . ';');
    $globalConstantRestored = eval('return ' . Export::closure($globalConstant) . ';');
    $intersectionRestored = eval('return ' . Export::closure($intersection) . ';');
    $dnfRestored = eval('return ' . Export::closure($dnf) . ';');

    $iterator = new ArrayIterator([1, 2]);

    expect($nullableRestored())->toBeNull()
        ->and($nullableRestored(4))->toBe(4)
        ->and($unionRestored())->toBe(ClosureSignatureDefaultFixture::VALUE)
        ->and($unionRestored('value'))->toBe('value')
        ->and($globalConstantRestored())->toBe(PHP_INT_MAX)
        ->and($intersectionRestored($iterator))->toBe($iterator)
        ->and($dnfRestored($iterator))->toBe($iterator)
        ->and($dnfRestored(['value']))->toBe(['value']);
});
