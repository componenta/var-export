<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

function unboundContextClosure(): Closure
{
    return function (): ?string {
        return isset($this) ? $this::class : null;
    };
}

function unboundReferenceClosure(int &$value): Closure
{
    return function () use (&$value): int {
        return ++$value;
    };
}

function unboundArrowClosure(int $value): Closure
{
    return fn(): array => [isset($this), $value];
}

final class ObjectContextClosureLoader
{
    public function load(string $code): Closure
    {
        /** @var Closure */
        return eval('return ' . $code . ';');
    }

    public function loadWithValue(string $code, int &$value): Closure
    {
        /** @var Closure */
        return eval('return ' . $code . ';');
    }
}

it('keeps an unbound non-static closure unbound when evaluated inside an object', function (): void {
    $original = unboundContextClosure();
    $restored = (new ObjectContextClosureLoader())->load(Export::closure($original));

    expect((new ReflectionFunction($original))->getClosureThis())->toBeNull()
        ->and((new ReflectionFunction($restored))->getClosureThis())->toBeNull()
        ->and($restored())->toBeNull();
});

it('preserves by-reference captures while isolating ambient object context', function (): void {
    $value = 3;
    $original = unboundReferenceClosure($value);
    $code = Export::closure($original);
    $restored = (new ObjectContextClosureLoader())->loadWithValue($code, $value);

    expect((new ReflectionFunction($restored))->getClosureThis())->toBeNull()
        ->and($restored())->toBe(4)
        ->and($value)->toBe(4);
});

it('preserves implicit arrow captures while isolating ambient object context', function (): void {
    $value = 7;
    $original = unboundArrowClosure($value);
    $restored = (new ObjectContextClosureLoader())->loadWithValue(Export::closure($original), $value);

    expect($restored())->toBe([false, 7])
        ->and((new ReflectionFunction($restored))->getClosureThis())->toBeNull();
});

it('keeps zero-capture Inline closures unbound', function (): void {
    $original = unboundContextClosure();
    $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
    $restored = (new ObjectContextClosureLoader())->load(Export::closure($original, $config));

    expect((new ReflectionFunction($restored))->getClosureThis())->toBeNull()
        ->and($restored())->toBeNull();
});
