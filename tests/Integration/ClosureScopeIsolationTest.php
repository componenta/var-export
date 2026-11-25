<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Tests\Support\ObjectContextExpressionEvaluator;
use Componenta\VarExport\VarExport;
use Error;

it('does not grant an unscoped closure access to private members of its loading class', function (): void {
    require_once __DIR__ . '/../Fixture/UnscopedPrivateConstantClosure.php';

    $original = \Componenta\VarExport\Tests\Fixture\unscopedPrivateConstantClosure();
    $restored = new ObjectContextExpressionEvaluator()->evaluateClosure(VarExport::withDefaults()->export($original));

    expect(ObjectContextExpressionEvaluator::privateValue())->toBe(42)
        ->and(fn (): mixed => $original(ObjectContextExpressionEvaluator::class))->toThrow(Error::class)
        ->and(fn (): mixed => $restored(ObjectContextExpressionEvaluator::class))->toThrow(Error::class);
});
