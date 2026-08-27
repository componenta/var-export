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
                return static fn(): string => __CLASS__;
            }
        }
    }

    return ComponentaDynamicFunctionOwner::make();
}

function dynamicTraitDeclaredInsideFunctionClosure(): Closure
{
    if (!trait_exists('ComponentaDynamicFunctionTrait', false)) {
        trait ComponentaDynamicFunctionTrait
        {
            public static function make(): Closure
            {
                return static fn(): string => __TRAIT__;
            }
        }

        class ComponentaDynamicFunctionTraitConsumer
        {
            use ComponentaDynamicFunctionTrait;
        }
    }

    return ComponentaDynamicFunctionTraitConsumer::make();
}

it('exports a closure from a named class declared inside a named function', function (): void {
    $closure = dynamicClassDeclaredInsideFunctionClosure();
    $expected = $closure();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBe($expected)
        ->and($expected)->toBe('ComponentaDynamicFunctionOwner');
});

it('exports a closure from a trait declared inside a named function', function (): void {
    $closure = dynamicTraitDeclaredInsideFunctionClosure();
    $expected = $closure();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($closure) . ';');

    expect($restored())->toBe($expected)
        ->and($expected)->toBe('ComponentaDynamicFunctionTrait');
});
