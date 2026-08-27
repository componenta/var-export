<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Export;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    public function testVarRoundTripsScalarValues(): void
    {
        foreach (['hello', 42, true, null] as $value) {
            self::assertSame($value, eval('return ' . Export::var($value) . ';'));
        }
    }

    public function testVarRoundTripsArray(): void
    {
        $value = ['a' => 1];

        self::assertSame($value, eval('return ' . Export::var($value) . ';'));
    }

    public function testPrettyRoundTripsWithPrettyFormatting(): void
    {
        $value = ['a' => 1, 'b' => 2];
        $code = Export::pretty($value);

        self::assertStringContainsString("\n", $code);
        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testPrettyOverridesCompactConfigMode(): void
    {
        $value = [1, 2];
        $code = Export::pretty($value, ExportConfig::compact());

        self::assertStringContainsString("\n", $code);
        self::assertSame($value, eval('return ' . $code . ';'));
    }

    public function testStatementProducesExecutablePhpStatement(): void
    {
        $value = ['a' => 1];
        $statement = Export::statement($value);

        self::assertStringEndsWith(';', $statement);
        self::assertSame($value, eval('return ' . $statement));
    }

    public function testArrayConvenienceMethodRoundTrips(): void
    {
        $value = ['x' => 'y'];

        self::assertSame($value, eval('return ' . Export::array($value) . ';'));
    }

    public function testClosureConvenienceMethodProducesEquivalentCallable(): void
    {
        $closure = static fn(int $value): int => $value + 1;
        $restored = eval('return ' . Export::closure($closure) . ';');

        self::assertInstanceOf(Closure::class, $restored);
        self::assertSame(42, $restored(41));
    }

    public function testConfigChangesObservableArrayOrder(): void
    {
        $config = new ExportConfig(sortKeys: true);
        $restored = eval('return ' . Export::var(['z' => 1, 'a' => 2], $config) . ';');

        self::assertSame(['a', 'z'], array_keys($restored));
        self::assertSame(['a' => 2, 'z' => 1], $restored);
    }

    public function testUnsupportedObjectThrowsTypedFailure(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('stdClass');

        Export::var(new \stdClass());
    }
}
