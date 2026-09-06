<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Closure;
use Componenta\VarExport\VarExport;
use LogicException;

use function Componenta\VarExport\Tests\Fixture\heredocClosure;
use function Componenta\VarExport\Tests\Fixture\multilineLiteralClosure;
use function Componenta\VarExport\Tests\Fixture\nowdocClosure;
use function Componenta\VarExport\Tests\Support\evaluateArrayExpression;

it('round-trips a multiline string returned by a closure nested in an array', function (): void {
    $expression = VarExport::withDefaults()->export(['poem' => multilineLiteralClosure()]);
    $restored = evaluateArrayExpression($expression);
    $poem = $restored['poem'] ?? null;
    if (!$poem instanceof Closure) {
        throw new LogicException('Expected the restored poem to be a Closure.');
    }

    expect($poem())->toBe("first\n    second\n        third");
});

it('round-trips heredoc and nowdoc values returned by closures nested in arrays', function (
    Closure $closure,
    string $expected,
): void {
    $expression = VarExport::withDefaults()->export(['render' => $closure]);
    $restored = evaluateArrayExpression($expression);
    $render = $restored['render'] ?? null;
    if (!$render instanceof Closure) {
        throw new LogicException('Expected the restored renderer to be a Closure.');
    }

    expect($render())->toBe($expected);
})->with([
    'heredoc' => [heredocClosure(), "SELECT id, name\nFROM users\nWHERE active = 1"],
    'nowdoc' => [nowdocClosure(), "{{ greeting }},\n    welcome to \$site"],
]);
