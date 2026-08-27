<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Export;
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
        yield 'infinity' => [INF, '\\INF'];
        yield 'negative infinity' => [-INF, '-\\INF'];
        yield 'nan' => [NAN, '\\NAN'];
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

    public function testExportArrayRoundTrips(): void
    {
        $value = ['a' => 1, 'b' => 2];
        $code = $this->exporter->export($value);

        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testExportClosureProducesEquivalentCallable(): void
    {
        $closure = static fn(int $x): int => $x * 2;
        $code = $this->exporter->export($closure);
        $roundTripped = eval('return ' . $code . ';');

        self::assertSame(14, $roundTripped(7));
    }

    public function testStatementConvenienceCanBeExecuted(): void
    {
        $value = ['key' => 'value'];
        $statement = Export::statement($value);

        self::assertStringEndsWith(';', $statement);
        self::assertSame($value, eval('return ' . $statement));
    }

    public function testUnsupportedObjectThrowsTypedFailure(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('stdClass');

        $this->exporter->export(new \stdClass());
    }

    public function testResourceThrowsTypedFailure(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(ExportException::class);
            $this->exporter->export($resource);
        } finally {
            fclose($resource);
        }
    }

    public function testMaximumDepthAppliesToNestedValues(): void
    {
        $exporter = new VarExporter(new ExportConfig(maxDepth: 1));

        self::assertSame([1], eval('return ' . $exporter->export([1]) . ';'));

        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('Maximum nesting depth');
        $exporter->export([[1]]);
    }

    public function testConfigurationIsChosenAtConstructionBoundary(): void
    {
        $compact = new VarExporter(ExportConfig::compact());
        $pretty = new VarExporter(ExportConfig::pretty());

        self::assertStringNotContainsString("\n", $compact->export([1, 2]));
        self::assertStringContainsString("\n", $pretty->export([1, 2]));
        self::assertSame([1, 2], eval('return ' . $pretty->export([1, 2]) . ';'));
    }

    public function testSortedKeysChangeGeneratedIterationOrderWithoutChangingValues(): void
    {
        $exporter = new VarExporter(new ExportConfig(sortKeys: true));
        $result = $exporter->export(['z' => 1, 'a' => 2]);

        $aPos = strpos($result, "'a'");
        $zPos = strpos($result, "'z'");
        self::assertIsInt($aPos);
        self::assertIsInt($zPos);
        self::assertLessThan($zPos, $aPos);
        self::assertSame(['a' => 2, 'z' => 1], eval('return ' . $result . ';'));
    }

    public function testNestedStructureRoundTripsExactly(): void
    {
        $data = [
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
            'count' => 2,
        ];

        self::assertSame($data, eval('return ' . $this->exporter->export($data) . ';'));
    }

    public function testArrayWithClosuresRoundTripsBehavior(): void
    {
        $data = [
            'handler' => static fn(int $x): int => $x * 2,
            'value' => 42,
        ];

        $evaluated = eval('return ' . $this->exporter->export($data) . ';');

        self::assertSame(42, $evaluated['value']);
        self::assertSame(10, $evaluated['handler'](5));
    }
}
