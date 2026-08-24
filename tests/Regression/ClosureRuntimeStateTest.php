<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

final class ClosureLexicalScopeFixture
{
    private const string VALUE = 'lexical';

    public static function make(): Closure
    {
        return static fn(): array => [self::VALUE, __CLASS__, __METHOD__];
    }
}

final class ClosureRuntimeScopeFixture
{
    private const string VALUE = 'runtime';
}

final class ClosureGlobalRuntimeScopeFixture
{
}

trait ClosureReboundTraitFixture
{
    public static function makeTraitClosure(): Closure
    {
        return static fn(): array => [__CLASS__, __TRAIT__, __METHOD__];
    }
}

final class ClosureTraitConsumerFixture
{
    use ClosureReboundTraitFixture;
}

final class ClosureTraitRuntimeScopeFixture
{
}

it('rejects closure static locals whose live state cannot be reconstructed', function (): void {
    $closure = static function (): int {
        static $counter = 0;

        return ++$counter;
    };

    expect($closure())->toBe(1);
    expect($closure())->toBe(2);

    expect(fn() => Export::closure($closure))
        ->toThrow(ClosureExportException::class, 'static local variables');
});

it('preserves lexical magic constants for a globally defined closure rebound to a class scope', function (): void {
    $source = static fn(): array => [__CLASS__, __METHOD__];
    $bound = Closure::bind($source, null, ClosureGlobalRuntimeScopeFixture::class);

    expect($bound)->toBeInstanceOf(Closure::class);
    $expected = $bound();
    $restored = eval('return ' . Export::closure($bound) . ';');

    expect($restored())->toBe($expected);
});

it('separates lexical class magic constants from rebound runtime self scope', function (): void {
    $bound = Closure::bind(
        ClosureLexicalScopeFixture::make(),
        null,
        ClosureRuntimeScopeFixture::class,
    );

    expect($bound)->toBeInstanceOf(Closure::class);
    $expected = $bound();
    $restored = eval('return ' . Export::closure($bound) . ';');

    expect($expected[0])->toBe('runtime')
        ->and($expected[1])->toBe(ClosureLexicalScopeFixture::class)
        ->and($restored())->toBe($expected);
});

it('preserves trait magic constants when a trait closure is rebound to another runtime scope', function (): void {
    $bound = Closure::bind(
        ClosureTraitConsumerFixture::makeTraitClosure(),
        null,
        ClosureTraitRuntimeScopeFixture::class,
    );

    expect($bound)->toBeInstanceOf(Closure::class);
    $expected = $bound();
    $restored = eval('return ' . Export::closure($bound) . ';');

    expect($expected[0])->toBe(ClosureTraitRuntimeScopeFixture::class)
        ->and($expected[1])->toBe(ClosureReboundTraitFixture::class)
        ->and($restored())->toBe($expected);
});
