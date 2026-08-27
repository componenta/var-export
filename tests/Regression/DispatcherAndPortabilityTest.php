<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\SourcePathPolicy;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

require_once __DIR__ . '/../Fixture/PortabilityClosures.php';

use function Componenta\VarExport\Tests\Fixture\PortableImported\importedFunctionClosure;
use function Componenta\VarExport\Tests\Fixture\PortableImported\qualifiedFunctionClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\evalClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\fileClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\fullyQualifiedProviderLocalClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\unqualifiedConstantClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\unqualifiedFunctionClosure;

it('rejects namespace fallback function calls in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(unqualifiedFunctionClosure(), $config))->toThrow(ClosureExportException::class, 'unqualified function');
});

it('rejects namespace fallback constants in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(unqualifiedConstantClosure(), $config))->toThrow(ClosureExportException::class, 'unqualified constant');
});

it('allows imported and fully-qualified external functions in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    $imported = eval('return ' . Export::closure(importedFunctionClosure(), $config) . ';');
    $qualified = eval('return ' . Export::closure(qualifiedFunctionClosure(), $config) . ';');
    expect($imported())->toBe(3)->and($qualified())->toBe(3);
});

it('rejects provider-local named functions even when fully qualified', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(fullyQualifiedProviderLocalClosure(), $config))
        ->toThrow(ClosureExportException::class, 'provider source file');
});

it('rejects source paths under explicit source-path policy', function (): void {
    $config = (new ExportConfig())->withSourcePathPolicy(SourcePathPolicy::Reject);
    expect(fn() => Export::closure(fileClosure(), $config))->toThrow(ClosureExportException::class, '__FILE__');
});

it('portable-expression policy always rejects source paths', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(fileClosure(), $config))->toThrow(ClosureExportException::class, '__FILE__');
});

it('rejects eval in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(evalClosure(), $config))->toThrow(ClosureExportException::class, 'eval()');
});
