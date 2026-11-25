<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Closure;
use Componenta\VarExport\VarExport;
use LogicException;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

/** @return array{mixed, int} */
function invokeWithTicks(Closure $closure): array
{
    $ticks = 0;
    $handler = static function () use (&$ticks): void {
        ++$ticks;
    };
    register_tick_function($handler);

    try {
        $result = $closure();

        return [$result, $ticks];
    } finally {
        unregister_tick_function($handler);
    }
}

it('isolates unscoped tick declarations inside an exported closure from the rest of the generated file', function (): void {
    $body = 'static function (): int { declare(ticks=1); $value = 41; return $value + 1; }';
    $provider = createTemporaryPhpFile('componenta-ticks-provider-', '<?php declare(strict_types=1); return ' . $body . ';');
    $target = null;

    try {
        $original = requireClosureFile($provider);
        $expression = VarExport::withDefaults()->export($original);
        $target = createTemporaryPhpFile(
            'componenta-ticks-target-',
            '<?php declare(strict_types=1); return [' . $expression . ', static function (): int { $value = 41; return $value + 1; }];',
        );
        $closures = require $target;
        if (!is_array($closures) || !($closures[0] ?? null) instanceof Closure || !($closures[1] ?? null) instanceof Closure) {
            throw new LogicException('Expected the generated file to return two closures.');
        }

        $expected = invokeWithTicks($original);
        expect($expected[1])->toBeGreaterThan(0)
            ->and(invokeWithTicks($closures[0]))->toBe($expected)
            ->and(invokeWithTicks($closures[1]))->toBe([42, 0]);
    } finally {
        unlink($provider);
        if ($target !== null) {
            unlink($target);
        }
    }
});

it('does not inherit ticks from the generated file when the source closure has no ticks', function (): void {
    $provider = createTemporaryPhpFile(
        'componenta-ticks-disabled-',
        '<?php declare(strict_types=1); return static function (): int { $value = 41; return $value + 1; };',
    );
    $target = null;

    try {
        $original = requireClosureFile($provider);
        $expression = VarExport::withDefaults()->export($original);
        $target = createTemporaryPhpFile(
            'componenta-ticks-enabled-',
            '<?php declare(strict_types=1); declare(ticks=1); return ' . $expression . ';',
        );

        expect(invokeWithTicks(requireClosureFile($target)))->toBe([42, 0]);
    } finally {
        unlink($provider);
        if ($target !== null) {
            unlink($target);
        }
    }
});
