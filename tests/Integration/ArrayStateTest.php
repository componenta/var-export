<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;
use Error;

use function Componenta\VarExport\Tests\Support\evaluateArrayExpression;

it('rejects lossy array state captured by a closure', function (bool $nested): void {
    $values = ['kept', 'removed'];
    unset($values[1]);
    $captured = $nested ? ['nested' => $values] : $values;
    $closure = static function () use ($captured): array {
        return $captured;
    };

    expect(fn (): string => VarExport::withDefaults()->export($closure))
        ->toThrow(ExportException::class, 'next append index');
})->with([false, true]);

it('preserves append behavior for portable arrays', function (array $source, array $expected): void {
    $restored = evaluateArrayExpression(VarExport::withDefaults()->export($source));
    $restored[] = 'appended';

    expect($restored)->toBe($expected);
})->with([
    'empty' => [[], ['appended']],
    'list' => [['kept'], ['kept', 'appended']],
    'negative key' => [[-5 => 'kept'], [-5 => 'kept', -4 => 'appended']],
    'negative and string keys' => [['label' => 'kept', -5 => 'negative'], ['label' => 'kept', -5 => 'negative', -4 => 'appended']],
    'descending negative keys' => [[-5 => 'kept', -10 => 'negative'], [-5 => 'kept', -10 => 'negative', -4 => 'appended']],
    'minimum key' => [[PHP_INT_MIN => 'kept'], [PHP_INT_MIN => 'kept', PHP_INT_MIN + 1 => 'appended']],
    'string keys only' => [['label' => 'kept'], ['label' => 'kept', 0 => 'appended']],
    'mixed keys' => [[7 => 'kept', 'name' => 'value'], [7 => 'kept', 'name' => 'value', 8 => 'appended']],
]);

it('preserves the append error when the maximum integer key is occupied', function (): void {
    $source = [PHP_INT_MAX => 'kept'];
    $restored = evaluateArrayExpression(VarExport::withDefaults()->export($source));

    expect($restored)->toBe($source)
        ->and(static function () use ($restored): void {
            $restored[] = 'appended';
        })->toThrow(Error::class, 'next element is already occupied');
});
