<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Tests\Fixture\ClosurePayloadAttribute;
use Componenta\VarExport\VarExport;
use ReflectionFunction;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\overwriteFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('preserves losslessly normalized array keys in parameter defaults and attributes', function (
    string $literal,
    array $expected,
): void {
    $path = createTemporaryPhpFile(
        'componenta-literal-array-keys-',
        '<?php declare(strict_types=1); return #[\\' . ClosurePayloadAttribute::class . '(' . $literal . ')]'
        . ' static fn (array $value = ' . $literal . '): array => $value;',
    );

    try {
        $original = requireClosureFile($path);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));
        $reflection = new ReflectionFunction($restored);

        expect($restored())->toBe($expected)
            ->and($original())->toBe($expected)
            ->and($reflection->getParameters()[0]->getDefaultValue())->toBe($expected)
            ->and($reflection->getAttributes(ClosurePayloadAttribute::class)[0]->getArguments())->toBe([$expected]);
    } finally {
        unlink($path);
    }
})->with([
    'boolean keys' => ['[true => "yes", false => "no"]', [1 => 'yes', 0 => 'no']],
    'null key' => ['[null => "empty"]', ['' => 'empty']],
    'integral float keys' => ['[1.0 => "positive", -2.0 => "negative", -0.0 => "zero"]', [1 => 'positive', -2 => 'negative', 0 => 'zero']],
    'overlapping normalized keys' => ['[true => "first", 1.0 => "last", null => "empty"]', [1 => 'last', '' => 'empty']],
    'nested normalized keys' => ['[false => [null => [2.0 => "nested"]]]', [0 => ['' => [2 => 'nested']]]],
    'append after a negative key' => ['[-2.0 => "first", "next"]', [-2 => 'first', -1 => 'next']],
    'numeric and nonnumeric string keys' => ['["1" => "integer", "01" => "string"]', [1 => 'integer', '01' => 'string']],
]);

it('rejects changed source defaults that require lossy float key conversion without emitting a warning', function (string $literal): void {
    $path = createTemporaryPhpFile(
        'componenta-lossy-array-key-',
        '<?php declare(strict_types=1); return static fn (array $value = [1 => "value"]): array => $value;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, '<?php declare(strict_types=1); return static fn (array $value = ' . $literal . '): array => $value;');

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'parameter default');
    } finally {
        unlink($path);
    }
})->with([
    'fractional key' => ['[1.5 => "value"]'],
    'out-of-range key' => ['[1e30 => "value"]'],
]);
