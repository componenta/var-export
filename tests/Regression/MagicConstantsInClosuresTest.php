<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Export;

it('preserves all magic constant values of an unscoped closure', function (): void {
    $closure = static fn(): array => [
        __FILE__,
        __DIR__,
        __NAMESPACE__,
        __LINE__,
        __CLASS__,
        __METHOD__,
        __FUNCTION__,
        __TRAIT__,
    ];
    $expected = $closure();

    $code = Export::closure($closure);
    $evaluated = eval("return {$code};");

    expect($evaluated())->toBe($expected);
});
