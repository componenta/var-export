<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

/*
 * Regression for a duplicated array formatter inside ObjectExporter that
 * ignored pretty/sortKeys/trailingComma. An array nested inside a
 * readonly object used to come out compact - `[1, 2, 3]` - even when
 * the caller asked for multi-line pretty output. That was inconsistent
 * with how the same array looks at the top level.
 *
 * The fix delegates nested arrays inside objects to the main
 * ArrayExporter via a lazy provider, so the configured layout carries
 * through uniformly.
 */

final readonly class ArrayHoldingObject
{
    public function __construct(public array $items) {}
}

it('formats arrays inside readonly objects with pretty layout', function (): void {
    $object = new ArrayHoldingObject(['alpha', 'beta', 'gamma']);

    $code = Export::pretty($object);
    $evaluated = eval("return {$code};");

    // Pretty layout => each array item on its own line with indentation.
    expect($code)->toContain("'alpha'")
        ->and($code)->toContain("'beta'")
        ->and($code)->toContain("'gamma'")
        ->and(substr_count($code, "\n"))->toBeGreaterThanOrEqual(3);

    expect($evaluated)->toBeInstanceOf(ArrayHoldingObject::class)
        ->and($evaluated->items)->toBe(['alpha', 'beta', 'gamma']);
});

it('sorts keys inside arrays nested in readonly objects when asked', function (): void {
    $object = new ArrayHoldingObject(['zeta' => 3, 'alpha' => 1, 'mu' => 2]);

    $code = Export::var($object, new ExportConfig(sortKeys: true));

    // With sortKeys on, the alpha entry must appear before mu which must
    // appear before zeta inside the nested array.
    $alphaPos = strpos($code, "'alpha'");
    $muPos = strpos($code, "'mu'");
    $zetaPos = strpos($code, "'zeta'");

    expect($alphaPos)->toBeInt()
        ->and($muPos)->toBeInt()
        ->and($zetaPos)->toBeInt()
        ->and($alphaPos)->toBeLessThan($muPos)
        ->and($muPos)->toBeLessThan($zetaPos);
});

it('honours trailingComma for arrays nested in readonly objects', function (): void {
    $object = new ArrayHoldingObject([1, 2, 3]);

    $code = Export::pretty($object);

    // ExportConfig::pretty() sets trailingComma: true, so the last item
    // of the nested list must be followed by a comma before the closing
    // bracket.
    expect($code)->toMatch('/3,\s*\]/');
});
