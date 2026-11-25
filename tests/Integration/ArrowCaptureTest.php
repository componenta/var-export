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
    'undefined isset offset' => ['return static fn () => isset($undefined["key"]);', false],
    'undefined nested empty offset' => ['return static fn () => empty($undefined["a"]["b"]);', true],
    'undefined coalesce offset' => ['return static fn () => $undefined["key"] ?? 42;', 42],
    'undefined coalesce assignment' => ['return static fn () => ($undefined ??= 42);', 42],
    'undefined offset coalesce assignment' => ['return static fn () => ($undefined["key"] ??= 42);', 42],
    'undefined offset assignment' => ['return static fn () => ($undefined["key"] = 42);', 42],
    'undefined property isset' => ['return static fn () => isset($undefined->key);', false],
    'undefined property coalesce' => ['return static fn () => $undefined->key ?? 42;', 42],
    'defined offset with captured index' => ['$value = ["key" => 42]; $key = "key"; return static fn () => $value[$key] ?? 0;', 42],
    'undefined offset with captured index' => ['$key = "key"; return static fn () => $undefined[$key] ?? 42;', 42],
    'guard variable name' => ['return static fn () => isset($componentaVarExportStrictTypes);', false],
]);
