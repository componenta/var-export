<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

final readonly class ArrayHoldingObject { public function __construct(public array $items) {} }

function readonlyObjectConfig(?ExportConfig $config = null): ExportConfig
{
    return ($config ?? new ExportConfig())->withGenericReadonlyObjects();
}

it('formats arrays inside readonly objects with pretty layout', function (): void {
    $object = new ArrayHoldingObject(['alpha', 'beta', 'gamma']);
    $code = Export::pretty($object, readonlyObjectConfig());
    $evaluated = eval("return {$code};");
    expect($code)->toContain("'alpha'")->and($code)->toContain("'beta'")->and($code)->toContain("'gamma'")->and(substr_count($code, "\n"))->toBeGreaterThanOrEqual(3);
    expect($evaluated)->toBeInstanceOf(ArrayHoldingObject::class)->and($evaluated->items)->toBe(['alpha', 'beta', 'gamma']);
});

it('sorts keys inside arrays nested in readonly objects when asked', function (): void {
    $object = new ArrayHoldingObject(['zeta' => 3, 'alpha' => 1, 'mu' => 2]);
    $code = Export::var($object, readonlyObjectConfig(new ExportConfig(sortKeys: true)));
    $alphaPos = strpos($code, "'alpha'"); $muPos = strpos($code, "'mu'"); $zetaPos = strpos($code, "'zeta'");
    expect($alphaPos)->toBeInt()->and($muPos)->toBeInt()->and($zetaPos)->toBeInt()->and($alphaPos)->toBeLessThan($muPos)->and($muPos)->toBeLessThan($zetaPos);
});

it('honours trailingComma for arrays nested in readonly objects', function (): void {
    $object = new ArrayHoldingObject([1, 2, 3]);
    $code = Export::pretty($object, readonlyObjectConfig());
    expect($code)->toMatch('/3,\s*\]/');
});
