<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ArrayExportException;
use PHPUnit\Framework\TestCase;

final class ArrayExporterTest extends TestCase
{
    public function testCompactAndPrettyArraysRoundTrip(): void
    {
        $value = ['b' => [1, 2], 'a' => ['nested' => true]];

        $compact = (new ArrayExporter())->export($value);
        $pretty = (new ArrayExporter(ExportConfig::pretty()))->export($value);

        self::assertSame($value, eval("return {$compact};"));
        self::assertSame($value, eval("return {$pretty};"));
        self::assertStringContainsString("\n", $pretty);
    }

    public function testSortKeysUsesIntegerThenBytewiseStringOrdering(): void
    {
        $code = (new ArrayExporter(new ExportConfig(sortKeys: true)))->export([
            '2e0' => 1,
            10 => 2,
            '10e0' => 3,
            2 => 4,
            '02' => 5,
        ]);

        self::assertLessThan(strpos($code, '10 =>'), strpos($code, '2 =>'));
        self::assertLessThan(strpos($code, "'02'"), strpos($code, '10 =>'));
        self::assertLessThan(strpos($code, "'10e0'"), strpos($code, "'02'"));
        self::assertLessThan(strpos($code, "'2e0'"), strpos($code, "'10e0'"));
    }

    public function testArrayReferenceIsRejected(): void
    {
        $shared = 42;
        $value = ['shared' => &$shared];

        $this->expectException(ArrayExportException::class);
        $this->expectExceptionMessage('array reference');
        (new ArrayExporter())->export($value);
    }

    public function testMaximumDepthIsAppliedAtBoundary(): void
    {
        $exporter = new ArrayExporter(new ExportConfig(maxDepth: 2));

        self::assertSame('[[[1]]]', $exporter->export([[[1]]]));

        $this->expectException(ArrayExportException::class);
        $exporter->export([[[[1]]]]);
    }

    public function testStandaloneClosureExportFailsInsteadOfProducingPlaceholder(): void
    {
        $this->expectException(ArrayExportException::class);
        $this->expectExceptionMessage('ClosureExporterInterface');

        (new ArrayExporter())->export([static fn(): int => 1]);
    }

    public function testWithConfigReconfiguresFormatting(): void
    {
        $original = new ArrayExporter(ExportConfig::compact());
        $pretty = $original->withConfig(ExportConfig::pretty()->withIndent('  '));

        self::assertSame('[1, 2]', $original->export([1, 2]));
        self::assertStringContainsString("\n  1,", $pretty->export([1, 2]));
    }

    public function testExportAtDepthRejectsNegativeDepth(): void
    {
        $this->expectException(ArrayExportException::class);
        (new ArrayExporter())->exportAtDepth([], -1, '');
    }
}
