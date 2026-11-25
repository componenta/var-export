<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Unit;

use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\Tests\Double\CallbackExporter;
use Componenta\VarExport\VarExport;
use stdClass;

use function Componenta\VarExport\Tests\Support\evaluateExpression;

it('returns null for values that are not arrays without requiring a binding', function (): void {
    expect(new ArrayExporter()->export('not an array'))->toBeNull();
});

it('requires direct array exporters to be immutably bound first', function (): void {
    $exporter = new ArrayExporter();
    $bound = $exporter->withVarExport(VarExport::withDefaults());

    expect($bound)->not->toBe($exporter)
        ->and(fn (): ?string => $exporter->export([]))->toThrow(ExportException::class)
        ->and($bound->export([]))->toBe('[]');
});

it('exports an empty array on one line', function (): void {
    expect(VarExport::withDefaults()->export([]))->toBe('[]');
});

it('exports non-empty lists as four-space indented multiline expressions with a trailing comma', function (): void {
    expect(VarExport::withDefaults()->export([1, 'two']))->toBe(<<<'PHP'
[
    1,
    "two",
]
PHP);
});

it('preserves associative and mixed key order', function (): void {
    $value = ['first' => 1, 7 => 'seven', 'last' => true];

    expect(VarExport::withDefaults()->export($value))->toBe(<<<'PHP'
[
    "first" => 1,
    7 => "seven",
    "last" => true,
]
PHP);
});

it('exports the minimum integer key without float coercion', function (): void {
    $value = [PHP_INT_MIN => 'minimum'];
    $minimum = (string) (PHP_INT_MIN + 1) . ' - 1';
    $expression = VarExport::withDefaults()->export($value);

    expect($expression)->toBe("[\n    {$minimum} => \"minimum\",\n]")
        ->and(evaluateExpression($expression))->toBe($value);
});

it('formats nested arrays at four spaces per level', function (): void {
    $value = ['nested' => ['enabled' => true, 'ports' => [80, 443]]];

    expect(VarExport::withDefaults()->export($value))->toBe(<<<'PHP'
[
    "nested" => [
        "enabled" => true,
        "ports" => [
            80,
            443,
        ],
    ],
]
PHP);
});

it('delegates custom values through the common orchestrator and indents multiline expressions', function (): void {
    $customValue = new stdClass();
    $customExporter = new CallbackExporter(
        static fn (mixed $var): ?string => $var === $customValue
            ? "factory(\n    1,\n)"
            : null,
    );
    $varExport = new VarExport(
        $customExporter,
        new ScalarExporter(),
        new ArrayExporter(),
        new EnumExporter(),
        new ClosureExporter(),
    );

    expect($varExport->export(['custom' => $customValue]))->toBe(<<<'PHP'
[
    "custom" => factory(
        1,
    ),
]
PHP);
});

it('rejects arrays whose next append index cannot survive a literal export', function (array $array, int $removedKey, bool $nested): void {
    unset($array[$removedKey]);
    $value = $nested ? ['nested' => $array] : $array;

    expect(fn (): string => VarExport::withDefaults()->export($value))
        ->toThrow(ExportException::class, 'next append index');
})->with([
    'removed tail' => [['kept', 'removed'], 1, false],
    'empty after unset' => [[7 => 'removed'], 7, false],
    'nested empty array' => [[7 => 'removed'], 7, true],
    'maximum removed key' => [[PHP_INT_MAX => 'removed'], PHP_INT_MAX, false],
]);

it('rejects referenced array elements', function (): void {
    $value = 42;
    $array = [&$value];

    expect(fn (): string => VarExport::withDefaults()->export($array))
        ->toThrow(ExportException::class, 'reference');
});

it('rejects references nested inside child arrays', function (): void {
    $value = 42;
    $array = ['nested' => ['value' => &$value]];

    expect(fn (): string => VarExport::withDefaults()->export($array))
        ->toThrow(ExportException::class, '$array[\'nested\'][\'value\']');
});

it('rejects recursive reference arrays', function (): void {
    $array = [];
    $array['self'] = &$array;

    expect(fn (): string => VarExport::withDefaults()->export($array))
        ->toThrow(ExportException::class, 'reference');
});

it('applies exporter priority equally to arrays nested at every level', function (): void {
    $custom = new CallbackExporter(static fn (mixed $var): ?string => $var === [42] ? '[99]' : null);
    $exporter = new VarExport($custom, new ScalarExporter(), new ArrayExporter());

    expect(evaluateExpression($exporter->export([42])))->toBe([99])
        ->and(evaluateExpression($exporter->export([[42]])))->toBe([[99]])
        ->and(evaluateExpression($exporter->export(['nested' => [[42]]])))->toBe(['nested' => [[99]]]);
});

it('limits excessive array nesting without poisoning subsequent exports', function (): void {
    $value = [];
    for ($depth = 0; $depth < 256; ++$depth) {
        $value = [$value];
    }

    $exporter = VarExport::withDefaults();

    expect(fn (): string => $exporter->export($value))->toThrow(ExportException::class, 'deeper than 256')
        ->and($exporter->export([42]))->toBe("[\n    42,\n]");
});

it('preserves diagnostics through nested default and directly bound array exporters', function (bool $direct): void {
    $value = 42;
    $input = ['nested' => ['value' => &$value]];
    $chain = VarExport::withDefaults();
    $exporter = $direct ? (new ArrayExporter())->withVarExport($chain) : $chain;

    try {
        $exporter->export($input);
        throw new \LogicException('Expected the array reference to be rejected.');
    } catch (ExportException $error) {
        expect($error->context['path'])->toBe(['nested', 'value']);
    }
})->with([false, true]);

it('keeps independent recursive exports isolated when a custom exporter suspends a Fiber', function (bool $direct): void {
    $marker = new stdClass();
    $custom = new CallbackExporter(static function (mixed $var) use ($marker): ?string {
        if ($var !== $marker) {
            return null;
        }

        \Fiber::suspend('paused');

        return '42';
    });
    $chain = $direct
        ? new VarExport($custom, new ScalarExporter())
        : new VarExport($custom, new ScalarExporter(), new ArrayExporter());
    $exporter = $direct ? new ArrayExporter()->withVarExport($chain) : $chain;
    $nested = $marker;
    $expected = 42;
    for ($depth = 0; $depth < 256; ++$depth) {
        $nested = [$nested];
        $expected = [$expected];
    }

    $fiber = new \Fiber(static fn (): string => \Componenta\VarExport\Tests\Support\requireExpression($exporter->export($nested)));
    expect($fiber->start())->toBe('paused')
        ->and(evaluateExpression(\Componenta\VarExport\Tests\Support\requireExpression($exporter->export([1]))))->toBe([1]);
    $fiber->resume();

    $result = $fiber->getReturn();
    if (!is_string($result)) {
        throw new \LogicException('Expected the Fiber to return an expression.');
    }

    expect(evaluateExpression($result))->toBe($expected);
})->with([false, true]);

it('exports nested arrays directly with a chain that has no array exporter', function (): void {
    $bound = new ArrayExporter()->withVarExport(new VarExport(new ScalarExporter()));
    $value = ['nested' => [42]];

    expect($bound->export($value))->toBe(<<<'PHP'
[
    "nested" => [
        42,
    ],
]
PHP);
});

it('uses custom nested array exporters before the direct array fallback', function (): void {
    $custom = new CallbackExporter(static fn (mixed $var): ?string => $var === [42] ? '[99]' : null);
    $bound = new ArrayExporter()->withVarExport(new VarExport($custom, new ScalarExporter()));
    $expression = \Componenta\VarExport\Tests\Support\requireExpression($bound->export([[42]]));

    expect(evaluateExpression($expression))->toBe([[99]]);
});

it('uses the same array nesting limit for direct and orchestrated export', function (bool $withArrayExporter): void {
    $chain = $withArrayExporter
        ? new VarExport(new ScalarExporter(), new ArrayExporter())
        : new VarExport(new ScalarExporter());
    $bound = new ArrayExporter()->withVarExport($chain);
    $value = 42;
    for ($depth = 0; $depth < 256; ++$depth) {
        $value = [$value];
    }

    $expression = \Componenta\VarExport\Tests\Support\requireExpression($bound->export($value));
    expect(evaluateExpression($expression))->toBe($value)
        ->and(fn (): ?string => $bound->export([$value]))->toThrow(ExportException::class, 'deeper than 256');
})->with([false, true]);

it('does not retry a nested value after a custom exporter throws an unsupported-value exception', function (): void {
    $failure = new \Componenta\VarExport\Exception\UnsupportedValueException('custom failure');
    $custom = new CallbackExporter(static function (mixed $value) use ($failure): ?string {
        if (is_array($value)) {
            throw $failure;
        }

        return null;
    });
    $bound = new ArrayExporter()->withVarExport(new VarExport($custom, new ScalarExporter()));

    try {
        $bound->export([[42]]);
        throw new \LogicException('Expected the custom exporter exception.');
    } catch (\Componenta\VarExport\Exception\UnsupportedValueException $error) {
        expect($error)->toBe($failure);
    }
});
