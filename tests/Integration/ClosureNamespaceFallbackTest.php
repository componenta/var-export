<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('rejects ambiguous function fallback after PHP has cached a global function', function (): void {
    $namespace = 'ComponentaFallback' . bin2hex(random_bytes(8));
    $prefix = '<?php declare(strict_types=1); namespace ' . $namespace . '; ';
    $provider = createTemporaryPhpFile(
        'componenta-fallback-provider-',
        $prefix . 'return static fn (string $value): string => strtoupper($value);',
    );
    $dependency = createTemporaryPhpFile(
        'componenta-fallback-dependency-',
        $prefix . 'function strtoupper(string $value): string { return "shadow:" . $value; }',
    );
    $explicitGlobal = createTemporaryPhpFile(
        'componenta-fallback-global-',
        $prefix . 'return static fn (string $value): string => \strtoupper($value);',
    );
    $explicitLocal = createTemporaryPhpFile(
        'componenta-fallback-local-',
        $prefix . 'return static fn (string $value): string => namespace\strtoupper($value);',
    );

    try {
        $exporter = VarExport::withDefaults();
        $original = requireClosureFile($provider);

        expect($original('abc'))->toBe('ABC')
            ->and(evaluateClosureExpression($exporter->export($original))('abc'))->toBe('ABC');

        require $dependency;

        expect($original('abc'))->toBe('ABC')
            ->and(fn (): string => $exporter->export($original))
            ->toThrow(ExportException::class, 'ambiguous unqualified function');

        expect(evaluateClosureExpression($exporter->export(requireClosureFile($explicitGlobal)))('abc'))->toBe('ABC')
            ->and(evaluateClosureExpression($exporter->export(requireClosureFile($explicitLocal)))('abc'))->toBe('shadow:abc');
    } finally {
        unlink($provider);
        unlink($dependency);
        unlink($explicitGlobal);
        unlink($explicitLocal);
    }
});

it('allows unqualified calls when only the namespaced target exists outside the provider', function (): void {
    $namespace = 'ComponentaFallback' . bin2hex(random_bytes(8));
    $prefix = '<?php declare(strict_types=1); namespace ' . $namespace . '; ';
    $provider = createTemporaryPhpFile(
        'componenta-fallback-unique-',
        $prefix . 'return static fn (): int => componenta_fallback_unique_function();',
    );
    $dependency = createTemporaryPhpFile(
        'componenta-fallback-unique-dependency-',
        $prefix . 'function componenta_fallback_unique_function(): int { return 42; }',
    );

    try {
        require $dependency;
        $original = requireClosureFile($provider);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));

        expect($original())->toBe(42)
            ->and($restored())->toBe(42);
    } finally {
        unlink($provider);
        unlink($dependency);
    }
});
