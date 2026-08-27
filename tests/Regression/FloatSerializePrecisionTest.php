<?php

declare(strict_types=1);

use Componenta\VarExport\Export;

it('exports finite floats bit-exactly and deterministically across serialize_precision settings', function (): void {
    $values = [
        0.0,
        -0.0,
        0.10000000000000002,
        1.0000000000000002,
        PHP_FLOAT_MIN,
        PHP_FLOAT_MAX,
    ];
    $old = ini_get('serialize_precision');
    $outputs = array_fill(0, count($values), []);

    try {
        foreach ([3, 6, 14, 17, -1] as $precision) {
            ini_set('serialize_precision', (string) $precision);

            foreach ($values as $index => $value) {
                $code = Export::var($value);
                $restored = eval('return ' . $code . ';');

                expect($restored)->toBeFloat()
                    ->and(pack('d', $restored))->toBe(pack('d', $value));
                $outputs[$index][] = $code;
            }
        }
    } finally {
        if ($old !== false) {
            ini_set('serialize_precision', $old);
        }
    }

    foreach ($outputs as $codes) {
        expect(array_values(array_unique($codes)))->toHaveCount(1);
    }
});
