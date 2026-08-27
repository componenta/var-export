<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ExportConfig;
use PHPUnit\Framework\TestCase;

use function Componenta\VarExport\array_export;
use function Componenta\VarExport\closure_export;
use function Componenta\VarExport\var_export_pretty;
use function Componenta\VarExport\var_export_string;

final class FunctionsTest extends TestCase
{
    public function testVarExportStringRoundTripsArray(): void
    {
        $value = ['a' => 1];
        $code = var_export_string($value);

        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testVarExportStringPrettyRoundTripsWithPrettyFormatting(): void
    {
        $value = [1, 2];
        $code = var_export_string($value, pretty: true);

        self::assertStringContainsString("\n", $code);
        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testVarExportStringAppliesExplicitConfig(): void
    {
        $config = new ExportConfig(sortKeys: true);
        $code = var_export_string(['z' => 1, 'a' => 2], $config);
        $aPos = strpos($code, "'a'");
        $zPos = strpos($code, "'z'");

        self::assertIsInt($aPos);
        self::assertIsInt($zPos);
        self::assertLessThan($zPos, $aPos);
        self::assertSame(['a' => 2, 'z' => 1], eval('return ' . $code . ';'));
    }

    public function testVarExportPrettyRoundTrips(): void
    {
        $value = [1, 2, 3];
        $code = var_export_pretty($value);

        self::assertStringContainsString("\n", $code);
        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testArrayExportRoundTripsCompactArray(): void
    {
        $value = [1, 2, 3];
        $code = array_export($value);

        self::assertSame('[1, 2, 3]', $code);
        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testArrayExportPrettyRoundTrips(): void
    {
        $value = [1, 2];
        $code = array_export($value, pretty: true);

        self::assertStringContainsString("\n", $code);
        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testClosureExportProducesEquivalentArrowFunction(): void
    {
        $closure = static fn(int $value): int => $value + 1;
        $code = closure_export($closure);
        $restored = eval('return ' . $code . ';');

        self::assertSame(42, $restored(41));
    }

    public function testClosureExportPrettyProducesEquivalentClosure(): void
    {
        $closure = static function (int $value): int {
            return $value * 2;
        };
        $code = closure_export($closure, pretty: true);
        $restored = eval('return ' . $code . ';');

        self::assertStringContainsString("\n", $code);
        self::assertSame(10, $restored(5));
    }

    public function testVarExportStringScalarsUseExecutableRepresentations(): void
    {
        self::assertNull(eval('return ' . var_export_string(null) . ';'));
        self::assertTrue(eval('return ' . var_export_string(true) . ';'));
        self::assertFalse(eval('return ' . var_export_string(false) . ';'));
        self::assertSame(42, eval('return ' . var_export_string(42) . ';'));
        self::assertSame('hello', eval('return ' . var_export_string('hello') . ';'));
    }
}
