<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExporter;

readonly class LazyReadonlyValueFixture
{
    public function __construct(public int $value)
    {
    }
}

it('rejects an uninitialized lazy readonly object without running its initializer', function (): void {
    $reflection = new ReflectionClass(LazyReadonlyValueFixture::class);
    $initializations = 0;
    $lazy = $reflection->newLazyGhost(
        static function (LazyReadonlyValueFixture $object) use (&$initializations): void {
            ++$initializations;
            $property = (new ReflectionClass($object))->getProperty('value');
            $property->setRawValueWithoutLazyInitialization($object, 42);
        },
    );
    $exporter = new VarExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect($reflection->isUninitializedLazyObject($lazy))->toBeTrue();
    expect(fn() => $exporter->export($lazy))
        ->toThrow(ExportException::class, 'Uninitialized lazy readonly object');
    expect($initializations)->toBe(0)
        ->and($reflection->isUninitializedLazyObject($lazy))->toBeTrue();
});
