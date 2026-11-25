<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Tests\Fixture\SignatureScopeChild;
use Componenta\VarExport\VarExport;
use ReflectionFunction;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\overwriteFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('preserves integer literal defaults that PHP promotes according to the parameter type', function (string $type, mixed $expected): void {
    $path = createTemporaryPhpFile(
        'componenta-default-promotion-',
        '<?php declare(strict_types=1); return static fn (' . $type . ' $value = 1): ' . $type . ' => $value;',
    );

    try {
        $original = requireClosureFile($path);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));

        expect($original())->toBe($expected)
            ->and($restored())->toBe($expected)
            ->and((new ReflectionFunction($restored))->getParameters()[0]->getDefaultValue())
            ->toBe($expected);
    } finally {
        unlink($path);
    }
})->with([
    'float' => ['float', 1.0],
    'nullable float' => ['?float', 1.0],
    'float and string' => ['float|string', 1.0],
    'float and int' => ['float|int', 1],
    'mixed' => ['mixed', 1],
    'int' => ['int', 1],
]);

it('rejects a changed integer literal in a float default', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-changed-float-default-',
        '<?php declare(strict_types=1); return static fn (float $value = 1): float => $value;',
    );

    try {
        $original = requireClosureFile($path);
        overwriteFile($path, '<?php declare(strict_types=1); return static fn (float $value = 2): float => $value;');

        expect(fn (): string => VarExport::withDefaults()->export($original))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
});

it('rejects a change from an integer to a float default when the parameter accepts either', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-changed-default-type-',
        '<?php declare(strict_types=1); return static fn (int|float $value = 1): int|float => $value;',
    );

    try {
        $original = requireClosureFile($path);
        overwriteFile($path, '<?php declare(strict_types=1); return static fn (int|float $value = 1.0): int|float => $value;');

        expect(fn (): string => VarExport::withDefaults()->export($original))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
});

it('preserves lexical self and parent class constant defaults', function (): void {
    $original = SignatureScopeChild::scopedDefaults();
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));
    $parameters = (new ReflectionFunction($restored))->getParameters();

    expect($restored())->toBe([42, 41])
        ->and($restored())->toBe($original())
        ->and($parameters[0]->getDefaultValueConstantName())->toBe('self::DEFAULT_VALUE')
        ->and($parameters[1]->getDefaultValueConstantName())->toBe('parent::DEFAULT_VALUE');
});
