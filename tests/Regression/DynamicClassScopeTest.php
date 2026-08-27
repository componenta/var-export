<?php

declare(strict_types=1);

use Componenta\VarExport\Export;

function dynamicClassDeclaredInsideFunctionClosure(): Closure
{
    if (!class_exists('ComponentaDynamicFunctionOwner', false)) {
        class ComponentaDynamicFunctionOwner
        {
            public static function make(): Closure
            {
                return static fn(): string => __METHOD__;
            }
        }
    }

    return ComponentaDynamicFunctionOwner::make();
}

trait ComponentaDynamicOuterTrait
{
    public static function dynamicClassClosure(): Closure
    {
        if (!class_exists('ComponentaDynamicTraitNestedOwner', false)) {
            class ComponentaDynamicTraitNestedOwner
            {
                public static function make(): Closure
                {
                    return static fn(): string => __METHOD__;
                }
            }
        }

        return ComponentaDynamicTraitNestedOwner::make();
    }
}

final class ComponentaDynamicOuterTraitConsumer
{
    use ComponentaDynamicOuterTrait;
}

it('exports a closure from a named class declared inside a named function', function (): void {
    $closure = dynamicClassDeclaredInsideFunctionClosure();
    $expected = $closure();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBe($expected)
        ->and($expected)->toBe('ComponentaDynamicFunctionOwner::make');
});

it('exports a closure from a named class declared inside a trait method', function (): void {
    $closure = ComponentaDynamicOuterTraitConsumer::dynamicClassClosure();
    $expected = $closure();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBe($expected)
        ->and($expected)->toBe('ComponentaDynamicTraitNestedOwner::make');
});
