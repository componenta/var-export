<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VarExporterTest extends TestCase
{
    private VarExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new VarExporter();
    }

    public function testExportNull(): void
    {
        self::assertSame('null', $this->exporter->export(null));
    }

    public function testExportTrue(): void
    {
        self::assertSame('true', $this->exporter->export(true));
    }

    public function testExportFalse(): void
    {
        self::assertSame('false', $this->exporter->export(false));
    }

    #[DataProvider('integerProvider')]
    public function testExportInteger(int $value, string $expected): void
    {
        self::assertSame($expected, $this->exporter->export($value));
    }

    public static function integerProvider(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'positive' => [42, '42'];
        yield 'negative' => [-42, '-42'];
        yield 'large' => [PHP_INT_MAX, (string) PHP_INT_MAX];
    }

    #[DataProvider('floatProvider')]
    public function testExportFloat(float $value, string $expected): void
    {
        self::assertSame($expected, $this->exporter->export($value));
    }

    public static function floatProvider(): iterable
    {
        yield 'simple' => [3.14, '3.14'];
        yield 'negative' => [-3.14, '-3.14'];
        yield 'infinity' => [INF, 'INF'];
        yield 'negative infinity' => [-INF, '-INF'];
        yield 'nan' => [NAN, 'NAN'];
    }

    #[DataProvider('stringProvider')]
    public function testExportString(string $value, string $expected): void
    {
        self::assertSame($expected, $this->exporter->export($value));
    }

    public static function stringProvider(): iterable
    {
        yield 'simple' => ['hello', "'hello'"];
        yield 'empty' => ['', "''"];
        yield 'with quote' => ["it's", "'it\\'s'"];
        yield 'with backslash' => ['a\\b', "'a\\\\b'"];
    }

    public function testExportArray(): void
    {
        $result = $this->exporter->export(['a' => 1, 'b' => 2]);

        self::assertSame("['a' => 1, 'b' => 2]", $result);
    }

    public function testExportClosureProducesEvaluableCode(): void
    {
        $closure = static fn(int $x): int => $x * 2;

        $code = $this->exporter->export($closure);
        $roundTripped = eval("return {$code};");

        // Round-trip is the real contract; substring asserts would tolerate
        // mangled output that no longer executes correctly.
        self::assertSame(14, $roundTripped(7));
    }

    public function testExportToFile(): void
    {
        $result = $this->exporter->exportToFile(['key' => 'value']);

        self::assertStringEndsWith(';', $result);
    }

    public function testExportThrowsForObject(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('stdClass');

        $this->exporter->export(new \stdClass());
    }

    public function testExportThrowsForResource(): void
    {
        $this->expectException(ExportException::class);

        $resource = fopen('php://memory', 'r');
        try {
            $this->exporter->export($resource);
        } finally {
            fclose($resource);
        }
    }

    public function testWithConfigProducesIndependentInstanceUsingNewConfig(): void
    {
        $prettyConfig = ExportConfig::pretty();
        $original = new VarExporter(ExportConfig::compact());
        $pretty = $original->withConfig($prettyConfig);

        // Identity is secondary - the real guarantee is that the two
        // instances produce different output because the new config is
        // actually applied to subsequent exports.
        self::assertNotSame($original, $pretty);
        self::assertSame($prettyConfig, $pretty->getConfig());
        self::assertStringNotContainsString("\n", $original->export([1, 2]));
        self::assertStringContainsString("\n", $pretty->export([1, 2]));
    }

    public function testGetConfigReturnsConstructionConfig(): void
    {
        $config = ExportConfig::pretty();
        $exporter = new VarExporter($config);

        self::assertSame($config, $exporter->getConfig());
    }

    public function testExportWithPrettyConfig(): void
    {
        $exporter = new VarExporter(ExportConfig::pretty());

        $result = $exporter->export([1, 2]);

        self::assertStringContainsString("\n", $result);
    }

    public function testExportWithSortedKeys(): void
    {
        $config = new ExportConfig(sortKeys: true);
        $exporter = new VarExporter($config);

        $result = $exporter->export(['z' => 1, 'a' => 2]);

        $aPos = strpos($result, "'a'");
        $zPos = strpos($result, "'z'");
        self::assertLessThan($zPos, $aPos);
    }

    public function testExportNestedStructure(): void
    {
        $data = [
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
            'count' => 2,
        ];

        $result = $this->exporter->export($data);

        self::assertStringContainsString("'users'", $result);
        self::assertStringContainsString("'Alice'", $result);
        self::assertStringContainsString("'count' => 2", $result);
    }

    public function testExportArrayWithClosuresRoundTrips(): void
    {
        $data = [
            'handler' => static fn(int $x): int => $x * 2,
            'value' => 42,
        ];

        $code = $this->exporter->export($data);
        $evaluated = eval("return {$code};");

        // Structural substring matches would pass even if the closure body
        // were silently corrupted; we only know the output is correct when
        // it evaluates and behaves like the original.
        self::assertSame(42, $evaluated['value']);
        self::assertSame(10, $evaluated['handler'](5));
    }
}
