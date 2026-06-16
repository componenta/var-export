<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Export;

/*
 * Scenario coverage: file-level magic constants inside closure bodies.
 *
 * The MagicConstantResolver substitutes __FILE__, __DIR__,
 * __NAMESPACE__ and __LINE__ with their literal values at export time
 * so the evaluated closure reports the ORIGINAL location, not the
 * eval()'d one. Class/method/function/trait magic constants resolve
 * to an empty string - PHP itself reports '' for them at file top
 * level, so exported closures behave the same.
 */

it('substitutes __FILE__ with the literal source path', function (): void {
    $closure = static fn(): string => __FILE__;

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    expect($evaluated())->toBe(__FILE__);
});

it('substitutes __DIR__ with the literal source directory', function (): void {
    $closure = static fn(): string => __DIR__;

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    expect($evaluated())->toBe(__DIR__);
});

it('substitutes __NAMESPACE__ with the file namespace', function (): void {
    $closure = static fn(): string => __NAMESPACE__;

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    expect($evaluated())->toBe(__NAMESPACE__);
});

it('substitutes __LINE__ with the literal closure line', function (): void {
    $line = __LINE__ + 1;
    $closure = static fn(): int => __LINE__;

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    // The closure's __LINE__ is the line of the __LINE__ expression
    // itself - which is the same line as `static fn(): int => __LINE__`.
    expect($evaluated())->toBe($line);
});

it('resolves class context magic constants to empty string', function (): void {
    // __CLASS__/__METHOD__/__FUNCTION__/__TRAIT__ have no enclosing scope
    // once the closure is printed standalone, matching PHP's top-level
    // behaviour of returning ''.
    $closure = static fn(): array => [__CLASS__, __METHOD__, __FUNCTION__, __TRAIT__];

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    expect($evaluated())->toBe(['', '', '', '']);
});
