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
    private ClosureExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ClosureExporter();
    }

    public function testExportSimpleClosure(): void
    {
        $closure = static function () {
            return 42;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('function', $result);
        self::assertStringContainsString('return 42', $result);
    }

    public function testExportArrowFunction(): void
    {
        $closure = static fn() => 42;

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('fn', $result);
        self::assertStringContainsString('42', $result);
    }

    public function testExportClosureWithParameters(): void
    {
        $closure = static function (int $a, string $b): string {
            return $b . $a;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('int $a', $result);
        self::assertStringContainsString('string $b', $result);
        self::assertStringContainsString(': string', $result);
    }

    public function testExportClosureWithUsePreserveMode(): void
    {
        $value = 42;
        $closure = static function () use ($value) {
            return $value;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Preserve);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringContainsString('use', $result);
        self::assertStringContainsString('$value', $result);
    }

    public function testExportClosureWithUseInlineMode(): void
    {
        $value = 42;
        $closure = static function () use ($value) {
            return $value;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringNotContainsString('use', $result);
        self::assertStringContainsString('42', $result);
    }

    public function testExportClosureWithMultipleUseVariablesInlined(): void
    {
        $a = 1;
        $b = 'hello';
        $c = [1, 2, 3];
        $closure = static function () use ($a, $b, $c) {
            return [$a, $b, $c];
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringNotContainsString('use', $result);
        self::assertStringContainsString('1', $result);
        self::assertStringContainsString("'hello'", $result);
    }

    public function testExportThrowsForBoundThis(): void
    {
        $object = new class {
            public function getClosure(): \Closure
            {
                return function () {
                    return $this;
                };
            }
        };

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('$this');

        $this->exporter->export($object->getClosure());
    }

    public function testExportThrowsForInlineModeWithUnexportableValue(): void
    {
        $object = new \stdClass();
        $closure = static function () use ($object) {
            return $object;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('Cannot inline');

        $exporter->export($closure);
    }

    public function testExportStaticClosure(): void
    {
        $closure = static function () {
            return 'static';
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('static', $result);
        self::assertStringContainsString('function', $result);
    }

    public function testExportClosureWithDefaultParameterValue(): void
    {
        $closure = static function (int $x = 10) {
            return $x;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('$x = 10', $result);
    }

    public function testExportClosureWithNullableType(): void
    {
        $closure = static function (?string $value): ?int {
            return $value ? strlen($value) : null;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('?string', $result);
        self::assertStringContainsString('?int', $result);
    }

    public function testExportPrettyFormat(): void
    {
        $closure = static function () {
            $a = 1;
            $b = 2;
            return $a + $b;
        };

        $config = ExportConfig::pretty();
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringContainsString("\n", $result);
    }

    public function testExportCompactFormat(): void
    {
        $closure = static function () {
            $a = 1;
            $b = 2;
            return $a + $b;
        };

        $config = ExportConfig::compact();
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        // Should be more compact, fewer newlines
        $lines = explode("\n", $result);
        self::assertLessThanOrEqual(1, count($lines));
    }

    public function testWithConfigProducesIndependentInstanceUsingNewConfig(): void
    {
        $closure = static function () {
            return 42;
        };
        $original = new ClosureExporter(ExportConfig::compact());
        $pretty = $original->withConfig(ExportConfig::pretty());

        self::assertNotSame($original, $pretty);
        self::assertStringNotContainsString("\n", $original->export($closure));
        self::assertStringContainsString("\n", $pretty->export($closure));
    }

    public function testExportWithDepthIndentsBodyLinesWithConfiguredIndent(): void
    {
        $closure = static function () {
            return 42;
        };

        $config = new ExportConfig(
            mode: \Componenta\VarExport\Config\FormatterMode::Pretty,
            indent: '  ', // two spaces -> easier to assert precisely
        );
        $exporter = new ClosureExporter($config);
        $result = $exporter->exportWithDepth($closure, 2);

        // Depth 2 + two-space indent = four-space prefix on every body
        // line after the signature. The closing brace also sits at the
        // base indent (four spaces), not column 0.
        $lines = explode("\n", $result);
        self::assertGreaterThan(1, count($lines));
        self::assertStringStartsWith('static function', $lines[0]);
        // Body line: base indent (2*2=4) + inner indent (2) = six spaces.
        self::assertSame('      return 42;', $lines[1]);
        self::assertSame('    }', $lines[array_key_last($lines)]);
    }

    public function testExportClosureWithArrayReturnType(): void
    {
        $closure = static function (): array {
            return [];
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString(': array', $result);
    }

    public function testExportClosureWithUnionReturnType(): void
    {
        $closure = static function (): int|string {
            return 42;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('int|string', $result);
    }

    public function testExportClosureWithReferenceParameter(): void
    {
        $closure = static function (&$value): void {
            $value++;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('&$value', $result);
    }

    public function testExportClosureWithVariadicParameter(): void
    {
        $closure = static function (...$args): int {
            return count($args);
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('...$args', $result);
    }

    public function testExportClosureWithTypedVariadicParameter(): void
    {
        $closure = static function (int ...$numbers): int {
            return array_sum($numbers);
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('int ...$numbers', $result);
    }

    public function testExportClosureWithMixedReturnType(): void
    {
        $closure = static function (): mixed {
            return null;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString(': mixed', $result);
    }

    public function testExportClosureWithVoidReturnType(): void
    {
        $closure = static function (): void {
            // do nothing
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString(': void', $result);
    }

    public function testExportClosureWithNeverReturnType(): void
    {
        $closure = static function (): never {
            throw new \Exception('never returns');
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString(': never', $result);
    }

    public function testExportArrowFunctionWithComplexExpression(): void
    {
        $closure = static fn(int $x, int $y): int => ($x + $y) * 2;

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('fn', $result);
        self::assertStringContainsString('$x', $result);
        self::assertStringContainsString('$y', $result);
    }

    public function testExportClosureWithIntersectionType(): void
    {
        $closure = static function (\Countable&\Traversable $value): int {
            return count($value);
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('Countable', $result);
        self::assertStringContainsString('Traversable', $result);
    }

    public function testExportClosureWithByReferenceUse(): void
    {
        $value = 0;
        $closure = static function () use (&$value): void {
            $value++;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Preserve);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringContainsString('use', $result);
        self::assertStringContainsString('&$value', $result);
    }

    public function testExportClosureWithNoReturnStatement(): void
    {
        $closure = static function (int $x): void {
            echo $x;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('echo', $result);
    }

    public function testExportClosureWithMultipleStatements(): void
    {
        $closure = static function (int $a, int $b): int {
            $sum = $a + $b;
            $doubled = $sum * 2;
            return $doubled;
        };

        $result = $this->exporter->export($closure);

        self::assertStringContainsString('$sum', $result);
        self::assertStringContainsString('$doubled', $result);
        self::assertStringContainsString('return', $result);
    }

    public function testExportClosureWithInlinedBooleanUseVariable(): void
    {
        $flag = true;
        $closure = static function () use ($flag): bool {
            return $flag;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringNotContainsString('use', $result);
        self::assertStringContainsString('true', $result);
    }

    public function testExportClosureWithInlinedNullUseVariable(): void
    {
        $value = null;
        $closure = static function () use ($value): mixed {
            return $value;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringNotContainsString('use', $result);
        self::assertStringContainsString('null', $result);
    }

    public function testExportClosureWithInlinedFloatUseVariable(): void
    {
        $value = 3.14;
        $closure = static function () use ($value): float {
            return $value;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        self::assertStringNotContainsString('use', $result);
        self::assertStringContainsString('3.14', $result);
    }

    public function testExportClosureWithoutUseVariablesInInlineMode(): void
    {
        $closure = static function (int $x): int {
            return $x * 2;
        };

        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $exporter = new ClosureExporter($config);
        $result = $exporter->export($closure);

        // Should work fine without use variables
        self::assertStringContainsString('$x * 2', $result);
    }
}
