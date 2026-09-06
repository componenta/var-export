<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Unit;

use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\Tests\Fixture\DeploymentStage;
use Componenta\VarExport\Tests\Fixture\PureDeploymentStage;
use UnitEnum;

use function Componenta\VarExport\Tests\Support\evaluateExpression;
use function Componenta\VarExport\Tests\Support\requireExpression;

it('exports backed and pure enum cases as fully qualified case expressions', function (
    UnitEnum $case,
    string $expected,
): void {
    $expression = requireExpression(new EnumExporter()->export($case));

    expect($expression)->toBe($expected)
        ->and(evaluateExpression($expression))->toBe($case);
})->with([
    'backed enum' => [
        DeploymentStage::Production,
        '\\Componenta\\VarExport\\Tests\\Fixture\\DeploymentStage::Production',
    ],
    'pure enum' => [
        PureDeploymentStage::Local,
        '\\Componenta\\VarExport\\Tests\\Fixture\\PureDeploymentStage::Local',
    ],
]);

it('returns null for values that are not enum cases', function (): void {
    expect(new EnumExporter()->export('production'))->toBeNull();
});
