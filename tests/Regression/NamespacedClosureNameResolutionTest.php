<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression\NsA;

use Componenta\VarExport\Export;
use SplObjectStorage;

/*
 * Regression for a previous hand-rolled NameResolver that rewrote every
 * unqualified name inside a closure to a fully-qualified one under the
 * current namespace. That was wrong for PHP's function- and constant-
 * fallback semantics: `array_map(...)` inside `namespace App` does NOT
 * live at `\App\array_map`, yet the rewriter emitted exactly that,
 * producing a fatal "undefined function" when the exported code was
 * evaluated in a different scope.
 *
 * The tests live inside a named namespace on purpose - the bug only
 * manifested for closures captured from namespaced source files.
 */

it('keeps unqualified function calls unqualified so runtime fallback works', function (): void {
    $closure = static fn(array $xs): int => array_sum($xs);

    $code = Export::closure($closure);

    // Key check: we must NOT see the current namespace prepended to a
    // core-PHP function name. If the rewriter ever regresses, the
    // exported code will contain "\Componenta\VarExport\Tests\..."-prefixed
    // function calls that fail at runtime.
    expect($code)->not->toContain(__NAMESPACE__ . '\\array_sum');

    $roundTripped = eval("return {$code};");
    expect($roundTripped([1, 2, 3]))->toBe(6);
});

it('keeps unqualified constants unqualified so runtime fallback works', function (): void {
    $closure = static fn(): int => PHP_INT_MAX;

    $code = Export::closure($closure);

    expect($code)->not->toContain(__NAMESPACE__ . '\\PHP_INT_MAX');

    $roundTripped = eval("return {$code};");
    expect($roundTripped())->toBe(PHP_INT_MAX);
});

it('resolves unqualified class names to fully qualified form', function (): void {
    // Classes DO need FQN resolution - they have no global fallback.
    // SplObjectStorage is imported at the top of this file; the exported
    // closure must carry the FQN so it works outside this namespace.
    $closure = static fn(): SplObjectStorage => new SplObjectStorage();

    $code = Export::closure($closure);

    expect($code)->toContain('\\SplObjectStorage');

    $roundTripped = eval("return {$code};");
    expect($roundTripped())->toBeInstanceOf(SplObjectStorage::class);
});
