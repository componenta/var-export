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
    public function testVarExportString(): void
    {
        $result = var_export_string(['a' => 1]);

        self::assertSame("['a' => 1]", $result);
    }

    public function testVarExportStringPretty(): void
    {
        $result = var_export_string([1, 2], pretty: true);

        self::assertStringContainsString("\n", $result);
    }

    public function testVarExportStringWithConfig(): void
    {
        $config = new ExportConfig(sortKeys: true);
        $result = var_export_string(['z' => 1, 'a' => 2], $config);

        $aPos = strpos($result, "'a'");
        $zPos = strpos($result, "'z'");
        self::assertLessThan($zPos, $aPos);
    }

    public function testVarExportPretty(): void
    {
        $result = var_export_pretty([1, 2, 3]);

        self::assertStringContainsString("\n", $result);
        self::assertStringContainsString('1', $result);
    }

    public function testArrayExport(): void
    {
        $result = array_export([1, 2, 3]);

        self::assertSame('[1, 2, 3]', $result);
    }

    public function testArrayExportPretty(): void
    {
        $result = array_export([1, 2], pretty: true);

        self::assertStringContainsString("\n", $result);
    }

    public function testClosureExport(): void
    {
        $closure = static fn() => 42;

        $result = closure_export($closure);

        self::assertStringContainsString('fn', $result);
        self::assertStringContainsString('42', $result);
    }

    public function testClosureExportPretty(): void
    {
        $closure = static function () {
            return 42;
        };

        $result = closure_export($closure, pretty: true);

        self::assertStringContainsString('function', $result);
    }

    public function testVarExportStringScalars(): void
    {
        self::assertSame('null', var_export_string(null));
        self::assertSame('true', var_export_string(true));
        self::assertSame('false', var_export_string(false));
        self::assertSame('42', var_export_string(42));
        self::assertSame("'hello'", var_export_string('hello'));
    }
}
