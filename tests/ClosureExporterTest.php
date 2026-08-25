<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;
use PHPUnit\Framework\TestCase;

final class ClosureExporterTest extends TestCase
{
    public function testSimpleClosureRoundTrips(): void
    {
        $closure = static fn(int $x): int => $x * 2;
        $code = (new ClosureExporter(new ExportConfig(closureUseMode: ClosureUseMode::Inline)))->export($closure);
        $restored = eval("return {$code};");

        self::assertSame(14, $restored(7));
    }

    public function testInlineIsSelfContainedAndPreservesWrites(): void
    {
        $value = 4;
        $closure = static function () use ($value): int {
            ++$value;

            return $value;
        };

        $code = (new ClosureExporter(new ExportConfig(closureUseMode: ClosureUseMode::Inline)))->export($closure);
        $restored = eval("return {$code};");

        self::assertSame(5, $restored());
        self::assertSame(5, $restored());
    }

    public function testPreserveKeepsUseClause(): void
    {
        $value = 42;
        $closure = static function () use ($value): int {
            return $value;
        };
        $exporter = new ClosureExporter(new ExportConfig(closureUseMode: ClosureUseMode::Preserve));

        self::assertStringContainsString('use ($value)', $exporter->export($closure));
    }

    public function testInlineRejectsByReferenceCapture(): void
    {
        $value = 0;
        $closure = static function () use (&$value): void {
            ++$value;
        };

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('captured by reference');
        (new ClosureExporter(new ExportConfig(closureUseMode: ClosureUseMode::Inline)))->export($closure);
    }

    public function testBoundThisIsRejected(): void
    {
        $object = new class {
            public function closure(): \Closure
            {
                return function (): self {
                    return $this;
                };
            }
        };

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('$this');
        (new ClosureExporter())->export($object->closure());
    }

    public function testPrettyOutputUsesConfiguredIndentAtDepth(): void
    {
        $closure = static function (): int {
            return 42;
        };
        $exporter = new ClosureExporter(ExportConfig::pretty()->withIndent('  '));
        $code = $exporter->exportWithDepth($closure, 2);
        $restored = eval("return {$code};");

        self::assertStringContainsString("\n      return 42;", $code);
        self::assertSame(42, $restored());
    }

    public function testWithConfigReusesSourceCacheButAppliesNewMode(): void
    {
        $value = 7;
        $closure = static function () use ($value): int {
            return $value;
        };
        $inline = new ClosureExporter(new ExportConfig(closureUseMode: ClosureUseMode::Inline));
        $preserve = $inline->withConfig(new ExportConfig(closureUseMode: ClosureUseMode::Preserve));

        self::assertStringContainsString('use ($value)', $preserve->export($closure));
        $restored = eval('return ' . $inline->export($closure) . ';');
        self::assertSame(7, $restored());
    }

    public function testArrowFunctionTypesAndGlobalFunctionsRoundTrip(): void
    {
        $closure = static fn(array $values): int => array_sum($values);
        $code = (new ClosureExporter(new ExportConfig(closureUseMode: ClosureUseMode::Inline)))->export($closure);
        $restored = eval("return {$code};");

        self::assertSame(6, $restored([1, 2, 3]));
        self::assertStringContainsString('\\array_sum', $code);
    }
}
