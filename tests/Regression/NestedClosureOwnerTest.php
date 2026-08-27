<?php

declare(strict_types=1);

use Componenta\VarExport\Export;

require_once __DIR__ . '/../Fixture/ClosureFactories.php';

use function Componenta\VarExport\Tests\Fixture\nestedMagicFactory;

final class DirectNestedClosureOwnerFixture
{
    public static function make(): Closure
    {
        return static function (): Closure {
            return static fn(): array => [__FUNCTION__, __METHOD__, __CLASS__];
        };
    }

    public static function makeDeep(): Closure
    {
        return static function (): Closure {
            return static function (): Closure {
                return static fn(): string => __FUNCTION__;
            };
        };
    }
}

trait DirectNestedClosureOwnerTrait
{
    public static function makeNestedFromTrait(): Closure
    {
        return static function (): Closure {
            return static fn(): array => [__FUNCTION__, __METHOD__, __TRAIT__, __CLASS__];
        };
    }
}

final class DirectNestedClosureOwnerTraitConsumer
{
    use DirectNestedClosureOwnerTrait;
}

it('directly exports a nested closure created inside a named function', function (): void {
    $inner = nestedMagicFactory()();
    $expected = $inner();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($inner) . ';');

    expect($restored())->toBe($expected);
});

it('directly exports deeply nested closures created inside a class method', function (): void {
    $inner = DirectNestedClosureOwnerFixture::makeDeep()()();
    $expected = $inner();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($inner) . ';');

    expect($restored())->toBe($expected);
});

it('directly exports nested closures created inside a trait method', function (): void {
    $inner = DirectNestedClosureOwnerTraitConsumer::makeNestedFromTrait()();
    $expected = $inner();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($inner) . ';');

    expect($restored())->toBe($expected);
});
