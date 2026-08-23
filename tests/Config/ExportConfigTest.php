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
        self::assertSame(ClosureUseMode::Preserve, $config->closureUseMode);
    }

    public function testCompactFactoryMethod(): void
    {
        self::assertSame(FormatterMode::Standard, ExportConfig::compact()->mode);
    }

    public function testCopyMethodsPreserveOtherOptions(): void
    {
        $config = new ExportConfig(
            mode: FormatterMode::Pretty,
            indent: '  ',
            maxDepth: 12,
            sortKeys: true,
            trailingComma: true,
            closureUseMode: ClosureUseMode::Preserve,
        );

        self::assertSame('    ', $config->withIndent('    ')->indent);
        self::assertSame(24, $config->withMaxDepth(24)->maxDepth);
        self::assertFalse($config->withSortKeys(false)->sortKeys);
        self::assertFalse($config->withTrailingComma(false)->trailingComma);
        self::assertSame(ClosureUseMode::Inline, $config->withClosureUseMode(ClosureUseMode::Inline)->closureUseMode);
        self::assertSame(FormatterMode::Standard, $config->withMode(FormatterMode::Standard)->mode);
    }

    #[DataProvider('invalidIndentProvider')]
    public function testThrowsForInvalidIndent(string $indent): void
    {
        $this->expectException(ConfigurationException::class);
        new ExportConfig(indent: $indent);
    }

    public static function invalidIndentProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['abc'];
        yield 'two tabs' => ["\t\t"];
        yield 'mixed whitespace' => [" \t "];
        yield 'space then text' => ['  x'];
    }

    #[DataProvider('validIndentProvider')]
    public function testAcceptsPrinterCompatibleIndent(string $indent): void
    {
        self::assertSame($indent, (new ExportConfig(indent: $indent))->indent);
    }

    public static function validIndentProvider(): iterable
    {
        yield 'one space' => [' '];
        yield 'two spaces' => ['  '];
        yield 'four spaces' => ['    '];
        yield 'tab' => ["\t"];
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
}
