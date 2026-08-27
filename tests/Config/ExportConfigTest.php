<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Config;

use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Config\SourcePathPolicy;
use Componenta\VarExport\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportConfigTest extends TestCase
{
    public function testDefaultConfigurationDefinesThePublicExportPolicy(): void
    {
        $config = new ExportConfig();

        self::assertSame(FormatterMode::Standard, $config->mode);
        self::assertSame('    ', $config->indent);
        self::assertSame(64, $config->maxDepth);
        self::assertFalse($config->sortKeys);
        self::assertFalse($config->trailingComma);
        self::assertSame(ClosureUseMode::Preserve, $config->closureUseMode);
        self::assertFalse($config->allowGenericReadonlyObjects);
        self::assertSame(ClosureExportPolicy::SourceBound, $config->closureExportPolicy);
        self::assertSame(SourcePathPolicy::AbsoluteBuildPath, $config->sourcePathPolicy);
    }

    public function testPrettyFactoryEnablesPrettyModeAndTrailingComma(): void
    {
        $config = ExportConfig::pretty();

        self::assertSame(FormatterMode::Pretty, $config->mode);
        self::assertTrue($config->trailingComma);
        self::assertSame(ClosureUseMode::Preserve, $config->closureUseMode);
    }

    public function testCompactFactoryUsesStandardMode(): void
    {
        self::assertSame(FormatterMode::Standard, ExportConfig::compact()->mode);
    }

    public function testCopyMethodsChangeTheRequestedOptionWithoutLosingOtherConfiguration(): void
    {
        $config = new ExportConfig(
            mode: FormatterMode::Pretty,
            indent: '  ',
            maxDepth: 12,
            sortKeys: true,
            trailingComma: true,
            closureUseMode: ClosureUseMode::Preserve,
            allowGenericReadonlyObjects: true,
            closureExportPolicy: ClosureExportPolicy::PortableExpression,
            sourcePathPolicy: SourcePathPolicy::Reject,
        );

        $withIndent = $config->withIndent('    ');
        self::assertSame('    ', $withIndent->indent);
        self::assertSame(FormatterMode::Pretty, $withIndent->mode);
        self::assertSame(12, $withIndent->maxDepth);
        self::assertTrue($withIndent->sortKeys);
        self::assertTrue($withIndent->trailingComma);
        self::assertSame(ClosureUseMode::Preserve, $withIndent->closureUseMode);
        self::assertTrue($withIndent->allowGenericReadonlyObjects);
        self::assertSame(ClosureExportPolicy::PortableExpression, $withIndent->closureExportPolicy);
        self::assertSame(SourcePathPolicy::Reject, $withIndent->sourcePathPolicy);

        self::assertSame(24, $config->withMaxDepth(24)->maxDepth);
        self::assertFalse($config->withSortKeys(false)->sortKeys);
        self::assertFalse($config->withTrailingComma(false)->trailingComma);
        self::assertSame(ClosureUseMode::Inline, $config->withClosureUseMode(ClosureUseMode::Inline)->closureUseMode);
        self::assertFalse($config->withGenericReadonlyObjects(false)->allowGenericReadonlyObjects);
        self::assertSame(
            ClosureExportPolicy::SourceBound,
            $config->withClosureExportPolicy(ClosureExportPolicy::SourceBound)->closureExportPolicy,
        );
        self::assertSame(
            SourcePathPolicy::AbsoluteBuildPath,
            $config->withSourcePathPolicy(SourcePathPolicy::AbsoluteBuildPath)->sourcePathPolicy,
        );
        self::assertSame(FormatterMode::Standard, $config->withMode(FormatterMode::Standard)->mode);

        self::assertSame('  ', $config->indent);
        self::assertSame(12, $config->maxDepth);
        self::assertTrue($config->sortKeys);
    }

    #[DataProvider('invalidIndentProvider')]
    public function testRejectsInvalidIndent(string $indent): void
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
    public function testRejectsInvalidMaxDepth(int $maxDepth): void
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
