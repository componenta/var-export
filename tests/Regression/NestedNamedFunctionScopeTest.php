<?php

declare(strict_types=1);

use Componenta\VarExport\Export;

final class NestedNamedFunctionDeclarationFixture
{
    public static function closure(): Closure
    {
        if (!function_exists('componenta_var_export_nested_scope_factory')) {
            function componenta_var_export_nested_scope_factory(): Closure
            {
                return static fn(): array => [__CLASS__, __TRAIT__, __FUNCTION__, __METHOD__];
            }
        }

        return componenta_var_export_nested_scope_factory();
    }
}

it('does not leak an enclosing class into closures inside nested named functions', function (): void {
    $original = NestedNamedFunctionDeclarationFixture::closure();
    $expected = $original();
    $restored = eval('return ' . Export::closure($original) . ';');

    expect($expected[0])->toBe('')
        ->and($expected[1])->toBe('')
        ->and($restored())->toBe($expected)
        ->and((new ReflectionFunction($restored))->getClosureScopeClass())->toBeNull();
});
