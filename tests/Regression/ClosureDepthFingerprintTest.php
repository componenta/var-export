<?php

declare(strict_types=1);

use Componenta\VarExport\Export;

function sameLineNestedClosureFactory(): Closure { return static fn() => static fn() => 42; }

it('disambiguates directly exported same-line nested closures by lexical closure depth', function (): void {
    $outer = sameLineNestedClosureFactory();
    $inner = $outer();

    /** @var Closure $restoredOuter */
    $restoredOuter = eval('return ' . Export::closure($outer) . ';');
    /** @var Closure $restoredInner */
    $restoredInner = eval('return ' . Export::closure($inner) . ';');

    expect(($restoredOuter())())->toBe(42)
        ->and($restoredInner())->toBe(42);
});

it('resets closure depth inside a named function declared in an outer closure', function (): void {
    $outer = static function (): Closure {
        if (!function_exists('componentaNestedDepthNamedFunction')) {
            function componentaNestedDepthNamedFunction(): Closure { return static fn() => 43; }
        }

        return componentaNestedDepthNamedFunction();
    };
    $inner = $outer();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($inner) . ';');

    expect($restored())->toBe(43);
});

it('resets closure depth inside a class method declared in an outer closure', function (): void {
    $outer = static function (): Closure {
        if (!class_exists('ComponentaNestedDepthClassOwner', false)) {
            class ComponentaNestedDepthClassOwner
            {
                public static function make(): Closure { return static fn() => 44; }
            }
        }

        return ComponentaNestedDepthClassOwner::make();
    };
    $inner = $outer();

    /** @var Closure $restored */
    $restored = eval('return ' . Export::closure($inner) . ';');

    expect($restored())->toBe(44);
});
