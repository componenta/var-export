<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

final class ComplexDefaultSideEffectProbe
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }
}

it('rejects complex parameter defaults that cannot be compared without execution', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_complex_default_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents(
            $file,
            "<?php\nreturn static fn(\$value = new \\stdClass()): string => \$value::class;\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'Cannot verify closure parameter default');
    } finally {
        @unlink($file);
    }
});

it('does not accept changed complex defaults merely because both are unverifiable', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_complex_default_stale_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents(
            $file,
            "<?php\nreturn static fn(\$value = new \\stdClass()): string => \$value::class;\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php\nreturn static fn(\$value = new \\ArrayObject()): string => \$value::class;\n",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'Cannot verify closure parameter default');
    } finally {
        @unlink($file);
    }
});

it('does not execute a runtime new default while verifying stale source', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_runtime_new_default_' . bin2hex(random_bytes(6)) . '.php';
    ComplexDefaultSideEffectProbe::$constructions = 0;

    try {
        file_put_contents(
            $file,
            "<?php\nreturn static fn(\$value = new \\ComplexDefaultSideEffectProbe()): mixed => \$value;\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php\nreturn static fn(\$value = null): mixed => \$value;\n",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'Cannot verify closure parameter default');
        expect(ComplexDefaultSideEffectProbe::$constructions)->toBe(0);
    } finally {
        @unlink($file);
    }
});

it('does not autoload classes while reading nested runtime class-constant defaults', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_runtime_class_constant_default_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $class = 'ComplexDefaultMissingClass' . $suffix;
    $autoloads = 0;
    $loader = static function (string $requested) use (&$autoloads, $class): void {
        if ($requested === $class) {
            $autoloads++;
        }
    };
    spl_autoload_register($loader);

    try {
        file_put_contents(
            $file,
            "<?php\nreturn static fn(\$value = [\\{$class}::VALUE]): mixed => \$value;\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'Cannot verify closure parameter default');
        expect($autoloads)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
        @unlink($file);
    }
});
