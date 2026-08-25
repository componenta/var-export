<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Export;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    public function testVarExportsString(): void
    {
        self::assertSame("'hello'", Export::var('hello'));
    }

    public function testVarExportsInteger(): void
    {
        self::assertSame('42', Export::var(42));
    }

    public function testVarExportsArray(): void
    {
        $result = Export::var(['a' => 1]);
        self::assertSame("['a' => 1]", $result);
    }

    public function testPrettyExportsWithNewlines(): void
    {
        $result = Export::pretty(['a' => 1, 'b' => 2]);

        self::assertStringContainsString("\n", $result);
    }

    public function testPrettyOverridesCompactConfigMode(): void
    {
        $result = Export::pretty([1, 2], ExportConfig::compact());

        self::assertStringContainsString("\n", $result);
    }

    public function testStatementEndsWithSemicolon(): void
    {
        $result = Export::statement(['a' => 1]);

        self::assertStringEndsWith(';', $result);
    }

    public function testArrayConvenienceMethod(): void
    {
        $result = Export::array(['x' => 'y']);

        self::assertSame("['x' => 'y']", $result);
    }

    public function testClosureConvenienceMethod(): void
    {
        $closure = static fn(): int => 42;
        $result = Export::closure($closure);
        $restored = eval("return {$result};");

        self::assertStringContainsString('fn(): int', $result);
        self::assertInstanceOf(\Closure::class, $restored);
        self::assertSame(42, $restored());
    }

    public function testConfigIsApplied(): void
    {
        $config = new ExportConfig(sortKeys: true);
        $result = Export::var(['z' => 1, 'a' => 2], $config);

        self::assertLessThan(strpos($result, "'z'"), strpos($result, "'a'"));
    }

    public function testUnsupportedObjectThrows(): void
    {
        $this->expectException(ExportException::class);

        Export::var(new \stdClass());
    }
}
