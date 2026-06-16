<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression\NsB;

use Componenta\VarExport\Export;
use Componenta\VarExport\Tests\Regression\NsA\ClosureFactory;

/*
 * Scenario coverage: a closure is captured inside one namespace, its
 * exported source is evaluated inside a DIFFERENT namespace. Unless
 * name resolution is correct, this is where the old NameResolver's
 * false-FQN for functions/constants would fail: the eval scope would
 * try to look up namespaced names that do not exist.
 *
 * The fix uses php-parser's NameResolver, which keeps unqualified
 * function/constant names as-is (preserving PHP's global fallback) and
 * fully qualifies class references.
 */

it('evaluates a closure captured elsewhere using unqualified functions', function (): void {
    // Closure lives in NsA, so the file that houses it is parsed in that
    // namespace. We export here (NsB) and eval here too; the exported
    // source must still refer to `array_sum` / `PHP_INT_MAX` without an
    // NsA prefix, otherwise eval'ing in NsB would fail to resolve them.
    $closure = ClosureFactory::sumClosure();

    $code = Export::closure($closure);

    expect($code)->not->toContain(__NAMESPACE__ . '\\array_sum')
        ->and($code)->not->toContain('Componenta\\VarExport\\Tests\\Regression\\NsA\\array_sum');

    $evaluated = eval("return {$code};");
    expect($evaluated([1, 2, 3, 4]))->toBe(10);
});

it('evaluates a closure that returns a global class via FQN', function (): void {
    $closure = ClosureFactory::splStorageFactoryClosure();

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    // Classes DO need fully qualified names because PHP has no global
    // fallback for class lookup - this test guards against a future
    // refactor silently dropping FQN for classes.
    expect($code)->toContain('\\SplObjectStorage');
    expect($evaluated())->toBeInstanceOf(\SplObjectStorage::class);
});
