<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

it('round-trips finite float and binary string captures exactly', function (): void {
    $float = 1.2345678901234567;
    $bytes = "\0" . '7' . implode('', array_map(chr(...), range(0, 255)));
    $closure = static fn(): array => [$float, $bytes];

    $code = Export::closure(
        $closure,
        new ExportConfig(closureUseMode: ClosureUseMode::Inline),
    );
    $restored = eval('return ' . $code . ';');
    [$restoredFloat, $restoredBytes] = $restored();

    expect(pack('d', $restoredFloat))->toBe(pack('d', $float))
        ->and($restoredBytes)->toBe($bytes);
});
