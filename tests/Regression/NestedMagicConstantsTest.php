<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Export;

require_once __DIR__ . '/../Fixture/ClosureFactories.php';

use function Componenta\VarExport\Tests\Fixture\nestedMagicFactory;

it('preserves magic function and method names inside nested closures', function (): void {
    $outer = nestedMagicFactory();
    $expectedInner = $outer();
    $expected = $expectedInner();

    $restoredOuter = eval('return ' . Export::closure($outer) . ';');
    $restoredInner = $restoredOuter();

    expect($restoredInner())->toBe($expected);
});
