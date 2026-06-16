<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Export;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    public function testVar(): void
    {
        self::assertSame('42', Export::var(42));
    }

    public function testVarWithNull(): void
    {
        self::assertSame('null', Export::var(null));
    }

    public function testVarWithBool(): void
    {
        self::assertSame('true', Export::var(true));
        self::assertSame('false', Export::var(false));
    }

    public function testVarWithString(): void
    {
        self::assertSame("'hello'", Export::var('hello'));
    }

    public function testVarWithArray(): void
    {
        $result = Export::var(['a' => 1, 'b' => 2]);

        self::assertSame("['a' => 1, 'b' => 2]", $result);
    }

    public function testVarWithConfig(): void
    {
        $config = new ExportConfig(sortKeys: true);

        $result = Export::var(['z' => 1, 'a' => 2], $config);

        $aPos = strpos($result, "'a'");
        $zPos = strpos($result, "'z'");
        self::assertLessThan($zPos, $aPos);
    }

    public function testPretty(): void
    {
        $result = Export::pretty([1, 2, 3]);

        self::assertStringContainsString("\n", $result);
    }

    public function testPrettyWithConfig(): void
    {
        $config = new ExportConfig(indent: "\t");

        $result = Export::pretty([1], $config);

        self::assertStringContainsString("\t", $result);
    }

    public function testToFile(): void
    {
        $result = Export::toFile(['key' => 'value']);

        self::assertStringEndsWith(';', $result);
    }

    public function testArray(): void
    {
        $result = Export::array([1, 2, 3]);

        self::assertSame('[1, 2, 3]', $result);
    }

    public function testClosureRoundTrips(): void
    {
        $closure = static fn(int $x): int => $x + 1;

        $code = Export::closure($closure);
        $evaluated = eval("return {$code};");

        self::assertSame(42, $evaluated(41));
    }

    public function testClosureWithPrettyConfigProducesMultilineRoundTrippingCode(): void
    {
        $closure = static function (int $a, int $b): int {
            $sum = $a + $b;
            return $sum * 2;
        };

        $code = Export::closure($closure, ExportConfig::pretty());
        $evaluated = eval("return {$code};");

        self::assertStringContainsString("\n", $code);
        self::assertSame(20, $evaluated(3, 7));
    }

    public function testVarThrowsForObject(): void
    {
        $this->expectException(ExportException::class);

        Export::var(new \stdClass());
    }

    public function testVarThrowsForResource(): void
    {
        $this->expectException(ExportException::class);

        $resource = fopen('php://memory', 'r');
        try {
            Export::var($resource);
        } finally {
            fclose($resource);
        }
    }
}
