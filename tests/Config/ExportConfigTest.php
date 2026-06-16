<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Config;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportConfigTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = new ExportConfig();

        self::assertSame(FormatterMode::Standard, $config->mode);
        self::assertSame('    ', $config->indent);
        self::assertSame(64, $config->maxDepth);
        self::assertFalse($config->sortKeys);
        self::assertFalse($config->trailingComma);
        self::assertSame(ClosureUseMode::Preserve, $config->closureUseMode);
    }

    public function testPrettyFactoryMethod(): void
    {
        $config = ExportConfig::pretty();

        self::assertSame(FormatterMode::Pretty, $config->mode);
        self::assertTrue($config->trailingComma);
    }

    public function testCompactFactoryMethod(): void
    {
        $config = ExportConfig::compact();

        self::assertSame(FormatterMode::Standard, $config->mode);
    }

    public function testWithModeReturnsNewInstance(): void
    {
        $config = new ExportConfig();
        $newConfig = $config->withMode(FormatterMode::Pretty);

        self::assertNotSame($config, $newConfig);
        self::assertSame(FormatterMode::Standard, $config->mode);
        self::assertSame(FormatterMode::Pretty, $newConfig->mode);
    }

    public function testWithIndentReturnsNewInstance(): void
    {
        $config = new ExportConfig();
        $newConfig = $config->withIndent("\t");

        self::assertNotSame($config, $newConfig);
        self::assertSame('    ', $config->indent);
        self::assertSame("\t", $newConfig->indent);
    }

    public function testWithMaxDepthReturnsNewInstance(): void
    {
        $config = new ExportConfig();
        $newConfig = $config->withMaxDepth(10);

        self::assertNotSame($config, $newConfig);
        self::assertSame(64, $config->maxDepth);
        self::assertSame(10, $newConfig->maxDepth);
    }

    public function testWithSortKeysReturnsNewInstance(): void
    {
        $config = new ExportConfig();
        $newConfig = $config->withSortKeys();

        self::assertNotSame($config, $newConfig);
        self::assertFalse($config->sortKeys);
        self::assertTrue($newConfig->sortKeys);
    }

    public function testWithTrailingCommaReturnsNewInstance(): void
    {
        $config = new ExportConfig();
        $newConfig = $config->withTrailingComma();

        self::assertNotSame($config, $newConfig);
        self::assertFalse($config->trailingComma);
        self::assertTrue($newConfig->trailingComma);
    }

    public function testWithClosureUseModeReturnsNewInstance(): void
    {
        $config = new ExportConfig();
        $newConfig = $config->withClosureUseMode(ClosureUseMode::Inline);

        self::assertNotSame($config, $newConfig);
        self::assertSame(ClosureUseMode::Preserve, $config->closureUseMode);
        self::assertSame(ClosureUseMode::Inline, $newConfig->closureUseMode);
    }

    public function testIsPrettyReturnsTrueForPrettyMode(): void
    {
        $config = new ExportConfig(mode: FormatterMode::Pretty);

        self::assertTrue($config->isPretty());
    }

    public function testIsPrettyReturnsFalseForStandardMode(): void
    {
        $config = new ExportConfig(mode: FormatterMode::Standard);

        self::assertFalse($config->isPretty());
    }

    #[DataProvider('invalidIndentProvider')]
    public function testThrowsForInvalidIndent(string $indent): void
    {
        $this->expectException(ConfigurationException::class);

        new ExportConfig(indent: $indent);
    }

    public static function invalidIndentProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'non-whitespace' => ['abc'];
        yield 'mixed' => ['  x'];
    }

    #[DataProvider('invalidMaxDepthProvider')]
    public function testThrowsForInvalidMaxDepth(int $maxDepth): void
    {
        $this->expectException(ConfigurationException::class);

        new ExportConfig(maxDepth: $maxDepth);
    }

    public static function invalidMaxDepthProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'very negative' => [-100];
    }

    #[DataProvider('validIndentProvider')]
    public function testAcceptsValidIndent(string $indent): void
    {
        $config = new ExportConfig(indent: $indent);

        self::assertSame($indent, $config->indent);
    }

    public static function validIndentProvider(): iterable
    {
        yield 'single space' => [' '];
        yield 'two spaces' => ['  '];
        yield 'four spaces' => ['    '];
        yield 'tab' => ["\t"];
        yield 'two tabs' => ["\t\t"];
        yield 'mixed whitespace' => [" \t "];
    }
}
