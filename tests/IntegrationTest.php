<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;
use Componenta\VarExport\VarExporter;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for complete export scenarios.
 */
final class IntegrationTest extends TestCase
{
    public function testExportedArrayCanBeEvaluated(): void
    {
        $original = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'nested' => [
                'a' => 1,
                'b' => 2,
            ],
        ];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame($original, $evaluated);
    }

    public function testExportedSequentialArrayCanBeEvaluated(): void
    {
        $original = [1, 2, 3, 'four', 5.0, true, null];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame($original, $evaluated);
    }

    public function testExportedClosureCanBeEvaluated(): void
    {
        $closure = static fn(int $x): int => $x * 2;

        $code = Export::closure($closure);
        $evaluated = eval("return {$code};");

        self::assertSame(10, $evaluated(5));
    }

    public function testExportedClosureWithBodyCanBeEvaluated(): void
    {
        $closure = static function (int $a, int $b): int {
            $sum = $a + $b;
            return $sum * 2;
        };

        $code = Export::closure($closure);
        $evaluated = eval("return {$code};");

        self::assertSame(14, $evaluated(3, 4));
    }

    public function testExportedStaticClosureCanBeEvaluated(): void
    {
        $closure = static fn(): string => 'static';

        $code = Export::closure($closure);
        $evaluated = eval("return {$code};");

        self::assertSame('static', $evaluated());
    }

    public function testExportedArrayWithClosureCanBeEvaluated(): void
    {
        $original = [
            'add' => static fn(int $a, int $b): int => $a + $b,
            'multiply' => static fn(int $a, int $b): int => $a * $b,
        ];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame(5, $evaluated['add'](2, 3));
        self::assertSame(6, $evaluated['multiply'](2, 3));
    }

    public function testExportedPrettyArrayCanBeEvaluated(): void
    {
        $original = ['a' => 1, 'b' => 2, 'c' => 3];

        $code = Export::pretty($original);
        $evaluated = eval("return {$code};");

        self::assertSame($original, $evaluated);
    }

    public function testExportedNestedArrayPreservesStructure(): void
    {
        $original = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep',
                    ],
                ],
            ],
        ];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame('deep', $evaluated['level1']['level2']['level3']['value']);
    }

    public function testExportedClosureWithInlinedUseCanBeEvaluated(): void
    {
        $multiplier = 3;
        $closure = static function (int $x) use ($multiplier): int {
            return $x * $multiplier;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $code = Export::closure($closure, $config);
        $evaluated = eval("return {$code};");

        self::assertSame(15, $evaluated(5));
    }

    public function testExportedClosureWithMultipleInlinedUsesCanBeEvaluated(): void
    {
        $a = 10;
        $b = 20;
        $closure = static function () use ($a, $b): int {
            return $a + $b;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $code = Export::closure($closure, $config);
        $evaluated = eval("return {$code};");

        self::assertSame(30, $evaluated());
    }

    public function testExportedClosureWithArrayInlinedUseCanBeEvaluated(): void
    {
        $items = [1, 2, 3];
        $closure = static function () use ($items): int {
            return \array_sum($items);
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $code = Export::closure($closure, $config);
        $evaluated = eval("return {$code};");

        self::assertSame(6, $evaluated());
    }

    public function testMultipleClosuresFromSameFileEachRoundTripIndependently(): void
    {
        // Previously called "testExporterReusesBenefitsFromCache", but it
        // never observed cache behavior - it only checked digits appeared
        // in output. The real guarantee to protect is that exporting
        // several closures from one file yields correct, independent code
        // for each one (the cache-hit path is internal and has no
        // observable side effects to assert on here).
        $exporter = new VarExporter();

        $fn1 = static fn(): int => 1;
        $fn2 = static fn(int $x): int => $x * 2;
        $fn3 = static fn(string $s): string => strtoupper($s);

        $e1 = eval('return ' . $exporter->export($fn1) . ';');
        $e2 = eval('return ' . $exporter->export($fn2) . ';');
        $e3 = eval('return ' . $exporter->export($fn3) . ';');

        self::assertSame(1, $e1());
        self::assertSame(8, $e2(4));
        self::assertSame('OK', $e3('ok'));
    }

    public function testToFileOutputIsValidPhp(): void
    {
        $data = ['config' => ['debug' => true, 'env' => 'test']];

        $code = Export::toFile($data);

        // Should be valid PHP that can be written to a file and included
        self::assertStringEndsWith(';', $code);
        self::assertStringStartsWith('[', $code);
    }

    public function testSpecialFloatValuesExport(): void
    {
        $original = [
            'inf' => INF,
            'neg_inf' => -INF,
            'nan' => NAN,
        ];

        $code = Export::var($original);

        self::assertStringContainsString('INF', $code);
        self::assertStringContainsString('-INF', $code);
        self::assertStringContainsString('NAN', $code);
    }

    public function testUnicodeStringsExport(): void
    {
        $original = [
            'russian' => 'Привет мир',
            'chinese' => '你好世界',
            'emoji' => '🚀🎉',
            'mixed' => 'Hello Мир 世界 🌍',
        ];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame($original, $evaluated);
    }

    public function testStringWithSpecialCharactersExport(): void
    {
        $original = [
            'quotes' => "He said \"hello\" and 'goodbye'",
            'backslash' => 'C:\\Users\\test',
            'newline' => "line1\nline2",
            'tab' => "col1\tcol2",
            'mixed' => "It's a \"test\" with \\ and \n",
        ];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame($original, $evaluated);
    }

    public function testEmptyValuesExport(): void
    {
        $original = [
            'empty_string' => '',
            'empty_array' => [],
            'zero' => 0,
            'zero_float' => 0.0,
            'false' => false,
            'null' => null,
        ];

        $code = Export::var($original);
        $evaluated = eval("return {$code};");

        self::assertSame($original, $evaluated);
    }

    public function testPhpIntMaxExport(): void
    {
        $code = Export::var(PHP_INT_MAX);
        $evaluated = eval("return {$code};");

        self::assertSame(PHP_INT_MAX, $evaluated);
    }

    public function testSortedKeysExport(): void
    {
        $original = ['z' => 1, 'a' => 2, 'm' => 3];
        $config = new ExportConfig(sortKeys: true);

        $code = Export::var($original, $config);

        // Verify order in output string
        $posA = strpos($code, "'a'");
        $posM = strpos($code, "'m'");
        $posZ = strpos($code, "'z'");

        self::assertLessThan($posM, $posA);
        self::assertLessThan($posZ, $posM);
    }

    public function testMixedNumericAndStringKeysWithSorting(): void
    {
        $original = ['b' => 2, 1 => 'one', 'a' => 1, 0 => 'zero'];
        $config = new ExportConfig(sortKeys: true);

        $code = Export::var($original, $config);

        // Numeric keys should come first
        $pos0 = strpos($code, '0 =>');
        $pos1 = strpos($code, '1 =>');
        $posA = strpos($code, "'a'");
        $posB = strpos($code, "'b'");

        self::assertLessThan($posA, $pos0);
        self::assertLessThan($posA, $pos1);
        self::assertLessThan($posB, $posA);
    }
}
