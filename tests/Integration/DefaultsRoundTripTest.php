<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Closure;
use Componenta\VarExport\Exception\UnsupportedValueException;
use Componenta\VarExport\Tests\Fixture\DeploymentStage;
use Componenta\VarExport\VarExport;
use LogicException;
use stdClass;

use function Componenta\VarExport\Tests\Fixture\simpleClosure;
use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\openMemoryStream;

it('round-trips a mixed config graph through a linted PHP file', function (): void {
    $value = [
        'debug' => false,
        'stage' => DeploymentStage::Production,
        'limits' => ['workers' => 4, 'ratio' => 0.5],
        'transform' => simpleClosure(),
    ];
    $expression = VarExport::withDefaults()->export($value);
    $path = createTemporaryPhpFile(
        'componenta-var-export-',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn {$expression};\n",
    );

    try {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($path), $output, $exitCode);
        expect($exitCode)->toBe(0)
            ->and(implode("\n", $output))->toContain('No syntax errors detected');

        $restored = require $path;
        if (!is_array($restored)) {
            throw new LogicException('Expected the generated PHP file to return an array.');
        }
        $transform = $restored['transform'] ?? null;
        if (!$transform instanceof Closure) {
            throw new LogicException('Expected the restored transform to be a Closure.');
        }

        expect($restored['debug'])->toBeFalse()
            ->and($restored['stage'])->toBe(DeploymentStage::Production)
            ->and($restored['limits'])->toBe(['workers' => 4, 'ratio' => 0.5])
            ->and($transform(41))->toBe(42);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('returns expressions only, without a PHP tag, return statement wrapper or terminator', function (): void {
    $expression = VarExport::withDefaults()->export(['answer' => 42]);

    expect($expression)->not->toContain('<?php')
        ->and($expression)->not->toStartWith('return ')
        ->and($expression)->not->toEndWith(';');
});

it('does not support arbitrary objects and resources with defaults', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(new stdClass()))
        ->toThrow(UnsupportedValueException::class);

    $resource = openMemoryStream();
    try {
        expect(fn (): string => VarExport::withDefaults()->export($resource))
            ->toThrow(UnsupportedValueException::class);
    } finally {
        fclose($resource);
    }
});
