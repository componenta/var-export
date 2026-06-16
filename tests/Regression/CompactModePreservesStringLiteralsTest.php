<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

/*
 * Regression for a previous formatCompact() implementation that ran a
 * `preg_replace('/\s+/', ' ', $code)` over the PrettyPrinter output. That
 * collapsed every run of whitespace, including whitespace inside quoted
 * string literals in the closure body - silently corrupting user data.
 *
 * To actually catch the regression, each test has to construct a closure
 * whose PrettyPrinter output contains *literal* whitespace bytes inside
 * a quoted string. For single-quoted source strings the printer preserves
 * the bytes as-is, so a regex over the whole output would match and
 * collapse them.
 */

it('preserves consecutive spaces inside single-quoted literals', function (): void {
    // 'a    b' is a single-quoted source literal whose PrettyPrinter
    // output contains four real space bytes inside the quotes - exactly
    // what the bad regex would collapse.
    $closure = static fn(): string => 'a    b';

    $compact = Export::var($closure, ExportConfig::compact());
    $roundTripped = eval("return {$compact};");

    expect($roundTripped())->toBe('a    b');
});

it('preserves literal newlines inside single-quoted multi-line literals', function (): void {
    // This single-quoted literal contains a real \n byte between "line1"
    // and "line2". PrettyPrinter prints the same literal bytes, so a
    // `\s+` regex would collapse the newline to a single space and
    // corrupt the value.
    $closure = static fn(): string => 'line1
line2';

    $compact = Export::var($closure, ExportConfig::compact());
    $roundTripped = eval("return {$compact};");

    expect($roundTripped())->toBe("line1\nline2");
});

it('produces a single line for multi-statement closures in compact mode', function (): void {
    // The positive-side contract of compact mode: the output is one line
    // and still evaluates to the original behavior.
    $closure = static function (int $a, int $b): int {
        $sum = $a + $b;
        return $sum * 2;
    };

    $compact = Export::var($closure, ExportConfig::compact());

    expect($compact)->not->toContain("\n");

    $roundTripped = eval("return {$compact};");
    expect($roundTripped(3, 7))->toBe(20);
});
