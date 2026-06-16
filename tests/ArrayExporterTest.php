<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Exception\ArrayExportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrayExporterTest extends TestCase
{
    private ArrayExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ArrayExporter();
    }

    public function testExportEmptyArray(): void
    {
        self::assertSame('[]', $this->exporter->export([]));
    }

    public function testExportSequentialArrayCompact(): void
    {
        $result = $this->exporter->export([1, 2, 3]);

        self::assertSame('[1, 2, 3]', $result);
    }

    public function testExportAssociativeArrayCompact(): void
    {
        $result = $this->exporter->export(['a' => 1, 'b' => 2]);

        self::assertSame("['a' => 1, 'b' => 2]", $result);
    }

    public function testExportMixedKeysArrayCompact(): void
    {
        $result = $this->exporter->export([0 => 'a', 'key' => 'b', 1 => 'c']);

        self::assertSame("[0 => 'a', 'key' => 'b', 1 => 'c']", $result);
    }

    public function testExportNestedArrayCompact(): void
    {
        $result = $this->exporter->export(['a' => [1, 2], 'b' => [3, 4]]);

        self::assertSame("['a' => [1, 2], 'b' => [3, 4]]", $result);
    }

    public function testExportPrettyEmptyArray(): void
    {
        $exporter = new ArrayExporter(ExportConfig::pretty());

        self::assertSame('[]', $exporter->export([]));
    }

    public function testExportPrettySequentialArray(): void
    {
        $exporter = new ArrayExporter(ExportConfig::pretty());
        $result = $exporter->export([1, 2, 3]);

        $expected = <<<'PHP'
[
    1,
    2,
    3,
]
PHP;

        self::assertSame($expected, $result);
    }

    public function testExportPrettyAssociativeArray(): void
    {
        $exporter = new ArrayExporter(ExportConfig::pretty());
        $result = $exporter->export(['a' => 1, 'b' => 2]);

        $expected = <<<'PHP'
[
    'a' => 1,
    'b' => 2,
]
PHP;

        self::assertSame($expected, $result);
    }

    public function testExportPrettyNestedArray(): void
    {
        $exporter = new ArrayExporter(ExportConfig::pretty());
        $result = $exporter->export([
            'level1' => [
                'level2' => [1, 2],
            ],
        ]);

        $expected = <<<'PHP'
[
    'level1' => [
        'level2' => [
            1,
            2,
        ],
    ],
]
PHP;

        self::assertSame($expected, $result);
    }

    public function testExportWithoutTrailingComma(): void
    {
        $config = new ExportConfig(
            mode: FormatterMode::Pretty,
            trailingComma: false,
        );
        $exporter = new ArrayExporter($config);
        $result = $exporter->export([1, 2]);

        $expected = <<<'PHP'
[
    1,
    2
]
PHP;

        self::assertSame($expected, $result);
    }

    public function testExportWithSortedKeys(): void
    {
        $config = new ExportConfig(sortKeys: true);
        $exporter = new ArrayExporter($config);
        $result = $exporter->export(['z' => 1, 'a' => 2, 0 => 3, 1 => 4]);

        // Numeric keys first, then alphabetically sorted string keys
        self::assertSame("[0 => 3, 1 => 4, 'a' => 2, 'z' => 1]", $result);
    }

    public function testExportWithCustomIndent(): void
    {
        $config = new ExportConfig(
            mode: FormatterMode::Pretty,
            indent: "\t",
            trailingComma: true,
        );
        $exporter = new ArrayExporter($config);
        $result = $exporter->export(['a' => 1]);

        $expected = "[\n\t'a' => 1,\n]";

        self::assertSame($expected, $result);
    }

    #[DataProvider('scalarValuesProvider')]
    public function testExportScalarValues(mixed $value, string $expected): void
    {
        $result = $this->exporter->export([$value]);

        self::assertSame("[{$expected}]", $result);
    }

    public static function scalarValuesProvider(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'integer' => [42, '42'];
        yield 'negative integer' => [-42, '-42'];
        yield 'float' => [3.14, '3.14'];
        yield 'string' => ['hello', "'hello'"];
        yield 'empty string' => ['', "''"];
        yield 'string with quotes' => ["it's", "'it\\'s'"];
    }

    public function testExportThrowsForObject(): void
    {
        $this->expectException(ArrayExportException::class);

        $this->exporter->export([new \stdClass()]);
    }

    public function testExportThrowsForResource(): void
    {
        $this->expectException(ArrayExportException::class);

        $resource = fopen('php://memory', 'r');
        try {
            $this->exporter->export([$resource]);
        } finally {
            fclose($resource);
        }
    }

    public function testExportThrowsForMaxDepthExceeded(): void
    {
        $config = new ExportConfig(maxDepth: 2);
        $exporter = new ArrayExporter($config);

        $this->expectException(ArrayExportException::class);
        $this->expectExceptionMessage('Maximum nesting depth');

        $exporter->export([[[[]]]]);
    }

    public function testWithConfigProducesIndependentInstanceUsingNewConfig(): void
    {
        $original = new ArrayExporter(ExportConfig::compact());
        $pretty = $original->withConfig(ExportConfig::pretty());

        // Identity is secondary - the real guarantee is that the new
        // config is actually applied to subsequent exports.
        self::assertNotSame($original, $pretty);
        self::assertStringNotContainsString("\n", $original->export([1, 2]));
        self::assertStringContainsString("\n", $pretty->export([1, 2]));
    }

    public function testExportWithSemicolon(): void
    {
        $result = $this->exporter->exportWithSemicolon([1, 2]);

        self::assertSame('[1, 2];', $result);
    }

    public function testExportStringWithSpecialCharacters(): void
    {
        $result = $this->exporter->export([
            'path' => 'C:\\Users\\test',
            'quote' => "He said \"hello\"",
        ]);

        self::assertStringContainsString("'C:\\\\Users\\\\test'", $result);
    }

    public function testExportDeeplyNestedArray(): void
    {
        $nested = ['level' => 1];
        for ($i = 2; $i <= 10; $i++) {
            $nested = ['level' => $i, 'child' => $nested];
        }

        $result = $this->exporter->export($nested);

        self::assertStringContainsString("'level' => 10", $result);
        self::assertStringContainsString("'level' => 1", $result);
    }

    public function testExportArrayWithNumericStringKeys(): void
    {
        $result = $this->exporter->export(['0' => 'a', '1' => 'b']);

        // PHP converts numeric string keys to integers
        self::assertSame("['a', 'b']", $result);
    }

    public function testExportArrayWithGaps(): void
    {
        $result = $this->exporter->export([0 => 'a', 5 => 'b', 10 => 'c']);

        // Non-sequential, so keys must be shown
        self::assertSame("[0 => 'a', 5 => 'b', 10 => 'c']", $result);
    }

    public function testExportArrayWithNegativeKeys(): void
    {
        $result = $this->exporter->export([-1 => 'a', 0 => 'b', 1 => 'c']);

        self::assertSame("[-1 => 'a', 0 => 'b', 1 => 'c']", $result);
    }

    public function testExportSingleElementArray(): void
    {
        $result = $this->exporter->export([42]);

        self::assertSame('[42]', $result);
    }

    public function testExportPrettySingleElementArray(): void
    {
        $exporter = new ArrayExporter(ExportConfig::pretty());
        $result = $exporter->export([42]);

        $expected = <<<'PHP'
[
    42,
]
PHP;

        self::assertSame($expected, $result);
    }

    public function testExportArrayWithSpecialFloatValues(): void
    {
        $result = $this->exporter->export([
            'inf' => INF,
            'neg_inf' => -INF,
            'nan' => NAN,
        ]);

        self::assertStringContainsString("'inf' => INF", $result);
        self::assertStringContainsString("'neg_inf' => -INF", $result);
        self::assertStringContainsString("'nan' => NAN", $result);
    }

    public function testExportArrayWithEmptyStringKey(): void
    {
        $result = $this->exporter->export(['' => 'empty key']);

        self::assertStringContainsString("'' => 'empty key'", $result);
    }

    public function testExportArrayWithUnicodeKeys(): void
    {
        $result = $this->exporter->export([
            'ключ' => 'value1',
            '键' => 'value2',
        ]);

        self::assertStringContainsString("'ключ'", $result);
        self::assertStringContainsString("'键'", $result);
    }

    public function testExportArrayWithMultilineString(): void
    {
        $result = $this->exporter->export([
            'text' => "line1\nline2\nline3",
        ]);

        self::assertStringContainsString("'text' => 'line1\nline2\nline3'", $result);
    }

    public function testExportArrayWithTabsInString(): void
    {
        $result = $this->exporter->export([
            'text' => "col1\tcol2\tcol3",
        ]);

        self::assertStringContainsString("'col1\tcol2\tcol3'", $result);
    }

    public function testExportArrayWithZeroFloat(): void
    {
        $result = $this->exporter->export([0.0]);

        self::assertSame('[0.0]', $result);
    }

    public function testExportArrayWithScientificNotationFloat(): void
    {
        $result = $this->exporter->export([1.5e10]);

        self::assertStringContainsString('15000000000', $result);
    }

    public function testExportArrayAtMaxDepth(): void
    {
        $config = new ExportConfig(maxDepth: 3);
        $exporter = new ArrayExporter($config);

        // Exactly at max depth must succeed with an exact, round-trippable
        // representation - a containsString('1') guard would tolerate
        // nonsense like '[[[1 garbage]]]'.
        $result = $exporter->export([[[1]]]);

        self::assertSame('[[[1]]]', $result);
    }

    public function testExportArrayJustOverMaxDepth(): void
    {
        $config = new ExportConfig(maxDepth: 3);
        $exporter = new ArrayExporter($config);

        $this->expectException(ArrayExportException::class);

        $exporter->export([[[[[]]]]]);
    }

    public function testExportArrayPreservesKeyOrder(): void
    {
        $array = ['c' => 1, 'a' => 2, 'b' => 3];

        $result = $this->exporter->export($array);

        $posC = strpos($result, "'c'");
        $posA = strpos($result, "'a'");
        $posB = strpos($result, "'b'");

        self::assertLessThan($posA, $posC);
        self::assertLessThan($posB, $posA);
    }

    public function testExportEmptyNestedArrays(): void
    {
        $result = $this->exporter->export([
            'empty1' => [],
            'empty2' => [],
        ]);

        self::assertSame("['empty1' => [], 'empty2' => []]", $result);
    }

    public function testExportPrettyEmptyNestedArrays(): void
    {
        $exporter = new ArrayExporter(ExportConfig::pretty());
        $result = $exporter->export([
            'empty1' => [],
            'empty2' => [],
        ]);

        $expected = <<<'PHP'
[
    'empty1' => [],
    'empty2' => [],
]
PHP;

        self::assertSame($expected, $result);
    }

    public function testExportArrayWithOnlyNullValues(): void
    {
        $result = $this->exporter->export([null, null, null]);

        self::assertSame('[null, null, null]', $result);
    }

    public function testExportArrayWithBooleanValues(): void
    {
        $result = $this->exporter->export([true, false, true]);

        self::assertSame('[true, false, true]', $result);
    }

    public function testExportArrayWithMixedScalarTypes(): void
    {
        $result = $this->exporter->export([
            1,
            'string',
            3.14,
            true,
            null,
        ]);

        self::assertSame("[1, 'string', 3.14, true, null]", $result);
    }

    public function testExportTwoSpaceIndent(): void
    {
        $config = new ExportConfig(
            mode: FormatterMode::Pretty,
            indent: '  ',
            trailingComma: true,
        );
        $exporter = new ArrayExporter($config);
        $result = $exporter->export(['a' => 1]);

        $expected = "[\n  'a' => 1,\n]";

        self::assertSame($expected, $result);
    }
}
