<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

final class PropertyHookClosureFixture
{
    public Closure $callback {
        get {
            return static fn(): array => [__PROPERTY__, __FUNCTION__, __METHOD__];
        }
    }
}

it('preserves nested closure magic constants from property hooks', function (): void {
    $original = (new PropertyHookClosureFixture())->callback;
    $expected = $original();
    $restored = eval('return ' . Export::closure($original) . ';');

    expect($expected[0])->toBe('')
        ->and($restored())->toBe($expected);
});

it('rejects a property hook owner changed on disk after closure creation', function (): void {
    $file = sys_get_temp_dir() . '/componenta_property_owner_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $class = 'ComponentaVarExportPropertyOwner' . $suffix;

    try {
        file_put_contents(
            $file,
            "<?php class {$class} { public \\Closure \$a { get { return static fn(): string => __PROPERTY__; } } } return (new {$class}())->a;",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php class {$class} { public \\Closure \$b { get { return static fn(): string => __PROPERTY__; } } } return (new {$class}())->b;",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});
