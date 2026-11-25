<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Unit;

use Componenta\VarExport\ScalarExporter;
use stdClass;

use function Componenta\VarExport\Tests\Support\evaluateExpression;
use function Componenta\VarExport\Tests\Support\evaluateFloatExpression;
use function Componenta\VarExport\Tests\Support\requireExpression;

it('exports canonical scalar literals', function (mixed $value, string $expected): void {
    expect((new ScalarExporter())->export($value))->toBe($expected);
})->with([
    'null' => [null, 'null'],
    'true' => [true, 'true'],
    'false' => [false, 'false'],
    'zero' => [0, '0'],
    'negative integer' => [-42, '-42'],
    'minimum integer' => [PHP_INT_MIN, (string) (PHP_INT_MIN + 1) . ' - 1'],
    'maximum integer' => [PHP_INT_MAX, '9223372036854775807'],
    'integer-valued float' => [1.0, '1.0'],
    'positive signed zero' => [0.0, '0.0'],
    'negative signed zero' => [-0.0, '-0.0'],
    'positive infinity' => [INF, '\\INF'],
    'negative infinity' => [-INF, '-\\INF'],
    'not a number' => [NAN, '\\NAN'],
    'plain string' => ['value', '"value"'],
    'empty string' => ['', '""'],
    'control and binary bytes' => ["a\n\0\xFF", '"a\\n\\x00\\xFF"'],
    'remaining control escapes' => ["\t\v\f\r\e", '"\\t\\v\\f\\r\\e"'],
]);

it('round-trips integer boundaries without changing their type', function (int $value): void {
    $expression = requireExpression(new ScalarExporter()->export($value));

    expect(evaluateExpression($expression))->toBe($value);
})->with([
    'minimum integer' => [PHP_INT_MIN],
    'maximum integer' => [PHP_INT_MAX],
]);

it('round-trips finite floats without losing their bit representation', function (float $value): void {
    $expression = requireExpression(new ScalarExporter()->export($value));
    $restored = evaluateFloatExpression($expression);

    expect(pack('d', $restored))->toBe(pack('d', $value));
})->with([
    0.1,
    1.2345678901234567,
    PHP_FLOAT_MIN,
    PHP_FLOAT_MAX,
    5.0E-324,
    -5.0E-324,
]);

it('round-trips quotes, slashes, dollar signs and valid unicode', function (): void {
    $value = "Componenta: \\ \" \\$ Привет";
    $expression = requireExpression(new ScalarExporter()->export($value));

    expect(evaluateExpression($expression))->toBe($value);
});

it('round-trips every possible byte in a binary string', function (): void {
    $value = implode('', array_map(chr(...), range(0, 255)));
    $expression = requireExpression(new ScalarExporter()->export($value));

    expect(evaluateExpression($expression))->toBe($value);
});

it('exports floats independently of serialize_precision', function (): void {
    $value = 1.0000000000000002;
    $previous = ini_get('serialize_precision');
    $expressions = [];

    try {
        foreach ([3, 6, 14, 17, -1] as $precision) {
            ini_set('serialize_precision', (string) $precision);
            $expression = requireExpression(new ScalarExporter()->export($value));
            $expressions[] = $expression;

            expect(pack('d', evaluateFloatExpression($expression)))->toBe(pack('d', $value));
        }
    } finally {
        if ($previous !== false) {
            ini_set('serialize_precision', $previous);
        }
    }

    expect(array_values(array_unique($expressions)))->toHaveCount(1);
});

it('returns null for non-scalar values', function (mixed $value): void {
    expect((new ScalarExporter())->export($value))->toBeNull();
})->with([
    'array' => [[]],
    'object' => [new stdClass()],
]);

it('round-trips deterministic binary payloads of different lengths', function (): void {
    $exporter = new ScalarExporter();
    for ($index = 0; $index < 256; ++$index) {
        $value = substr(hash('sha256', pack('N', $index), true), 0, $index % 33);
        $expression = requireExpression($exporter->export($value));

        expect(evaluateExpression($expression))->toBe($value);
    }
});

it('preserves the bit representation of deterministic finite floating point values', function (): void {
    $exporter = new ScalarExporter();
    for ($index = 0; $index < 256; ++$index) {
        $bits = substr(hash('sha256', pack('N', $index), true), 0, 8);
        $unpacked = unpack('dvalue', $bits);
        $value = $unpacked === false ? null : $unpacked['value'];
        if (!is_float($value) || !is_finite($value)) {
            continue;
        }

        $expression = requireExpression($exporter->export($value));

        expect(pack('d', evaluateFloatExpression($expression)))->toBe($bits);
    }
});
