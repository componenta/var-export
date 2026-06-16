<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Export;

/*
 * Regression + scenario coverage for formatPretty() on closure bodies.
 *
 * Previously formatPretty() walked the printer output line by line and
 * prepended the base indent to every non-empty line. That prefix would
 * also land on newlines sitting inside a quoted *string* in the closure
 * body - silently mutating the literal the user ships.
 *
 * The critical regression is exercised by the multi-line single-quoted
 * case: php-parser preserves literal newline bytes inside `'...'` quotes
 * when it prints the AST, which is exactly the shape the buggy fix
 * would corrupt. The heredoc / nowdoc cases round-trip correctly in
 * either code path because the current printer emits escape sequences
 * for them - we keep those as scenario coverage so future printer
 * changes don't sneak in a surprise.
 */

it('preserves newlines inside a single-quoted multi-line literal when pretty-nested', function (): void {
    // Literal newline bytes sit INSIDE the quotes. The old formatPretty
    // would prepend the base indent after each of them.
    $closure = static fn(): string => 'first
    second
        third';

    $code = Export::pretty(['poem' => $closure]);
    $container = eval("return {$code};");

    expect($container['poem']())->toBe("first\n    second\n        third");
});

it('round-trips a heredoc body embedded in a nested closure', function (): void {
    $closure = static function (): string {
        return <<<SQL
            SELECT id, name
            FROM users
            WHERE active = 1
            SQL;
    };

    $code = Export::pretty(['query' => $closure]);
    $container = eval("return {$code};");

    $expected = <<<SQL
        SELECT id, name
        FROM users
        WHERE active = 1
        SQL;

    expect($container['query']())->toBe($expected);
});

it('round-trips a nowdoc body embedded in a nested closure', function (): void {
    $closure = static function (): string {
        return <<<'TEMPLATE'
            {{ greeting }},
                welcome to $site
            TEMPLATE;
    };

    $code = Export::pretty(['template' => $closure]);
    $container = eval("return {$code};");

    $expected = <<<'TEMPLATE'
        {{ greeting }},
            welcome to $site
        TEMPLATE;

    expect($container['template']())->toBe($expected);
});
