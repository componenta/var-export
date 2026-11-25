<?php

declare(strict_types=1);

use Componenta\VarExport\VarExport;

require __DIR__ . '/../vendor/autoload.php';

enum BenchmarkStage
{
    case Ready;
}

function benchmarkClosure(string $prefix): Closure
{
    return static fn (int $value): string => $prefix . ':' . $value;
}

function benchmarkBlockClosure(): Closure
{
    return static function (int $value): int {
        return ($value + 1) * 2;
    };
}

/** @param Closure(): mixed $operation */
function measure(string $name, int $iterations, Closure $operation): void
{
    $operation();
    $samples = [];
    for ($sample = 0; $sample < 5; ++$sample) {
        $start = hrtime(true);
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $operation();
        }

        $samples[] = (hrtime(true) - $start) / $iterations / 1000;
    }

    sort($samples, SORT_NUMERIC);
    printf("%-28s %12.3f us/op (%d iterations per sample)\n", $name, $samples[2], $iterations);
}

$exporter = VarExport::withDefaults();
$deep = 1;
for ($depth = 0; $depth < 64; ++$depth) {
    $deep = [$deep];
}

$cases = [
    'integer' => [42, 100000],
    'float' => [1.2345678912345678, 100000],
    'ASCII string' => [str_repeat('abc 123 ', 64), 10000],
    'UTF-8 string' => [str_repeat('Привет 世界 🙂 ', 24), 10000],
    'binary string' => [implode('', array_map(chr(...), range(0, 255))), 10000],
    'list (1000 items)' => [range(1, 1000), 100],
    'map (1000 items)' => [array_combine(array_map(static fn (int $i): string => 'key' . $i, range(1, 1000)), range(1, 1000)), 100],
    'nested config' => [array_fill(0, 40, ['config' => ['ports' => [80, 443], 'name' => 'componenta', 'enabled' => true], 'bytes' => "\0\xFF"]), 100],
    'deep array (64 levels)' => [$deep, 100],
    'enum' => [BenchmarkStage::Ready, 10000],
];

printf("PHP %s | opcache.enable_cli=%s | median of 5 samples\n", PHP_VERSION, ini_get('opcache.enable_cli'));
foreach ($cases as $name => [$value, $iterations]) {
    measure($name, $iterations, static fn (): string => $exporter->export($value));
}

$closure = benchmarkClosure('first');
$block = benchmarkBlockClosure();
measure('closure (fresh exporter)', 50, static fn (): string => VarExport::withDefaults()->export($closure));
measure('closure (reused exporter)', 200, static fn (): string => $exporter->export($closure));
measure('closure (block)', 200, static fn (): string => $exporter->export($block));

$changing = 0;
measure('closure (new captures)', 200, static function () use ($exporter, &$changing): string {
    return $exporter->export(benchmarkClosure((string) ++$changing));
});

$expression = $exporter->export($closure);
measure('load generated closure', 1000, static fn (): mixed => eval('return ' . $expression . ';'));
