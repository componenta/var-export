<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\overwriteFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('reuses source metadata without sharing captured values or mutating the cached syntax tree', function (): void {
    $source = '<?php declare(strict_types=1); $value = $GLOBALS["componenta_test_capture"]; return static fn (): string => strtoupper($value);';
    $path = createTemporaryPhpFile('componenta-source-cache-', $source);
    $exporter = VarExport::withDefaults();

    try {
        $GLOBALS['componenta_test_capture'] = 'first';
        $first = requireClosureFile($path);
        $GLOBALS['componenta_test_capture'] = 'second';
        $second = requireClosureFile($path);

        $firstExpression = $exporter->export($first);
        $secondExpression = $exporter->export($second);

        expect(evaluateClosureExpression($firstExpression)())->toBe('FIRST')
            ->and(evaluateClosureExpression($secondExpression)())->toBe('SECOND')
            ->and($exporter->export($first))->toBe($firstExpression);

        overwriteFile($path, '<?php declare(strict_types=1); return static fn (): string => "replacement";');
        expect(fn (): string => $exporter->export($first))->toThrow(ExportException::class, 'no longer matches');

        $replacement = requireClosureFile($path);
        expect(evaluateClosureExpression($exporter->export($replacement))())->toBe('replacement');
    } finally {
        unset($GLOBALS['componenta_test_capture']);
        unlink($path);
    }
});

it('does not share generated strict-mode guards between strict and coercive source files', function (): void {
    $strict = createTemporaryPhpFile('componenta-strict-cache-', '<?php declare(strict_types=1); return static fn (): int => 42;');
    $coercive = createTemporaryPhpFile('componenta-coercive-cache-', '<?php declare(strict_types=0); return static fn (): int => "42";');
    $target = null;
    $exporter = VarExport::withDefaults();

    try {
        $strictClosure = requireClosureFile($strict);
        $coerciveClosure = requireClosureFile($coercive);
        $strictExpression = $exporter->export($strictClosure);
        $coerciveExpression = $exporter->export($coerciveClosure);
        $target = createTemporaryPhpFile('componenta-coercive-target-', '<?php declare(strict_types=0); return ' . $coerciveExpression . ';');

        expect(evaluateClosureExpression($strictExpression)())->toBe(42)
            ->and(requireClosureFile($target)())->toBe(42)
            ->and($exporter->export($strictClosure))->toBe($strictExpression)
            ->and(fn (): \Closure => evaluateClosureExpression($coerciveExpression))->toThrow(ExportException::class, 'strict_types');
    } finally {
        unlink($strict);
        unlink($coercive);
        if ($target !== null) {
            unlink($target);
        }
    }
});

it('reports name resolution failures after a provider changes without reusing a stale source snapshot', function (): void {
    $source = '<?php declare(strict_types=1); use DateTime as Alias; return static fn (): string => Alias::class;';
    $path = createTemporaryPhpFile('componenta-invalid-alias-', $source);
    $exporter = VarExport::withDefaults();

    try {
        $closure = requireClosureFile($path);
        $expression = $exporter->export($closure);
        expect(evaluateClosureExpression($expression)())->toBe(\DateTime::class);

        overwriteFile(
            $path,
            '<?php declare(strict_types=1); use DateTime as Alias; use DateTimeImmutable as Alias; return static fn (): string => Alias::class;',
        );

        try {
            $exporter->export($closure);
            throw new \LogicException('Expected conflicting imports to be rejected.');
        } catch (ExportException $error) {
            expect($error->getMessage())->toContain('Cannot resolve names')
                ->and($error->getPrevious())->toBeInstanceOf(\PhpParser\Error::class)
                ->and($error->context['filename'])->toBe($path)
                ->and($error->getFile())->toBe($path);
        }

        overwriteFile($path, $source);
        expect($exporter->export($closure))->toBe($expression);
    } finally {
        unlink($path);
    }
});
