<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Internal;

use Componenta\VarExport\Internal\ValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueFormatterTest extends TestCase
{
    private ValueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ValueFormatter();
    }

    #[DataProvider('integerProvider')]
    public function testFormatNumericWithIntegers(int $value, string $expected): void
    {
        self::assertSame($expected, $this->formatter->formatNumeric($value));
    }

    public static function integerProvider(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'positive' => [42, '42'];
        yield 'negative' => [-42, '-42'];
        yield 'large' => [PHP_INT_MAX, (string) PHP_INT_MAX];
        yield 'small' => [PHP_INT_MIN, (string) PHP_INT_MIN];
    }

    #[DataProvider('floatProvider')]
    public function testFormatNumericWithFloats(float $value, string $expected): void
    {
        self::assertSame($expected, $this->formatter->formatNumeric($value));
    }

    public static function floatProvider(): iterable
    {
        yield 'zero' => [0.0, '0.0'];
        yield 'positive' => [3.14, '3.14'];
        yield 'negative' => [-3.14, '-3.14'];
        yield 'scientific' => [1.5E10, '15000000000.0'];
        yield 'small' => [0.001, '0.001'];
        yield 'infinity' => [INF, 'INF'];
        yield 'negative infinity' => [-INF, '-INF'];
        yield 'not a number' => [NAN, 'NAN'];
    }

    #[DataProvider('stringProvider')]
    public function testEscapeString(string $value, string $expected): void
    {
        self::assertSame($expected, $this->formatter->escapeString($value));
    }

    public static function stringProvider(): iterable
    {
        yield 'simple' => ['hello', "'hello'"];
        yield 'empty' => ['', "''"];
        yield 'with single quote' => ["it's", "'it\\'s'"];
        yield 'with backslash' => ['path\\to', "'path\\\\to'"];
        yield 'with both' => ["it's a \\path", "'it\\'s a \\\\path'"];
        yield 'unicode' => ['привет', "'привет'"];
        yield 'emoji' => ['🚀', "'🚀'"];
        yield 'newline' => ["line1\nline2", "'line1\nline2'"];
        yield 'tab' => ["col1\tcol2", "'col1\tcol2'"];
    }

    public function testFormatBoolTrue(): void
    {
        self::assertSame('true', $this->formatter->formatBool(true));
    }

    public function testFormatBoolFalse(): void
    {
        self::assertSame('false', $this->formatter->formatBool(false));
    }

    public function testFormatNull(): void
    {
        self::assertSame('null', $this->formatter->formatNull());
    }
}
