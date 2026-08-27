<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;
use Componenta\VarExport\VarExporter;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    public function testMixedNestedValueGraphRoundTripsExactly(): void
    {
        $original = [
            'string' => "It's a \"test\" with \\ and \n",
            'unicode' => 'Hello Мир 世界 🌍',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'empty' => [],
            'list' => [1, 2, 3, 'four', 5.0, false],
            'nested' => [
                'level2' => [
                    'value' => 'deep',
                ],
            ],
        ];

        $restored = eval('return ' . Export::var($original) . ';');

        self::assertSame($original, $restored);
    }

    public function testClosureGraphRoundTripsObservableBehavior(): void
    {
        $original = [
            'calculate' => static function (int $a, int $b): int {
                $sum = $a + $b;

                return $sum * 2;
            },
            'multiply' => static fn(int $a, int $b): int => $a * $b,
        ];

        $restored = eval('return ' . Export::var($original) . ';');

        self::assertSame(14, $restored['calculate'](3, 4));
        self::assertSame(12, $restored['multiply'](3, 4));
    }

    public function testInlineCapturesRoundTripAsFrozenValues(): void
    {
        $factor = 3;
        $offset = 2;
        $items = [1, 2, 3];
        $closure = static function (int $value) use ($factor, $offset, $items): int {
            return ($value * $factor) + $offset + array_sum($items);
        };
        $config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
        $code = Export::closure($closure, $config);

        $factor = 100;
        $offset = 100;
        $items = [100];
        $restored = eval('return ' . $code . ';');

        self::assertSame(23, $restored(5));
    }

    public function testMultipleClosuresFromOneSourceFileRoundTripIndependently(): void
    {
        $exporter = new VarExporter();

        $one = static fn(): int => 1;
        $double = static fn(int $x): int => $x * 2;
        $upper = static fn(string $value): string => strtoupper($value);

        $restoredOne = eval('return ' . $exporter->export($one) . ';');
        $restoredDouble = eval('return ' . $exporter->export($double) . ';');
        $restoredUpper = eval('return ' . $exporter->export($upper) . ';');

        self::assertSame(1, $restoredOne());
        self::assertSame(8, $restoredDouble(4));
        self::assertSame('OK', $restoredUpper('ok'));
    }

    public function testNonFiniteFloatsRoundTripBySemantics(): void
    {
        $restored = eval('return ' . Export::var([INF, -INF, NAN]) . ';');

        self::assertTrue(is_infinite($restored[0]));
        self::assertGreaterThan(0, $restored[0]);
        self::assertTrue(is_infinite($restored[1]));
        self::assertLessThan(0, $restored[1]);
        self::assertTrue(is_nan($restored[2]));
    }

    public function testCanonicalSortingIsObservableAcrossMixedKeyKinds(): void
    {
        $value = ['b' => 2, 1 => 'one', 'a' => 1, 0 => 'zero'];
        $code = Export::var($value, new ExportConfig(sortKeys: true));
        $restored = eval('return ' . $code . ';');

        self::assertSame([0 => 'zero', 1 => 'one', 'a' => 1, 'b' => 2], $restored);
    }
}
