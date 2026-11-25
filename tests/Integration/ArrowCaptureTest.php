<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\VarExport;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('preserves optional arrow captures without creating unrelated variables', function (string $source, mixed $expected): void {
    $path = createTemporaryPhpFile('componenta-arrow-captures-', '<?php declare(strict_types=1); ' . $source);
    try {
        $original = requireClosureFile($path);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));

        expect($original())->toBe($expected)
            ->and($restored())->toBe($expected)
            ->and((new \ReflectionFunction($restored))->getClosureUsedVariables())
            ->toBe((new \ReflectionFunction($original))->getClosureUsedVariables());
    } finally {
        unlink($path);
    }
})->with([
    'existing assignment target' => ['$value = 1; return static fn () => ($value = 2);', 2],
    'existing destructuring target' => ['$value = 1; return static fn () => [$value] = [2];', [2]],
    'undefined isset target' => ['return static fn () => isset($undefined);', false],
    'defined isset target' => ['$value = 1; return static fn () => isset($value);', true],
    'undefined empty target' => ['return static fn () => empty($undefined);', true],
    'undefined coalesce target' => ['return static fn () => $undefined ?? 42;', 42],
    'guard variable name' => ['return static fn () => isset($componentaVarExportStrictTypes);', false],
]);
