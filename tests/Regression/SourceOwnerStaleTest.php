<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects a class owner changed on disk after the runtime closure was created', function (): void {
    $file = sys_get_temp_dir() . '/componenta_class_owner_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $classA = 'ComponentaVarExportOwnerA' . $suffix;
    $classB = 'ComponentaVarExportOwnerB' . $suffix;

    try {
        file_put_contents($file, "<?php class {$classA} { public static function make(): \\Closure { return static fn(): string => __CLASS__; } } return {$classA}::make();");
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents($file, "<?php class {$classB} { public static function make(): \\Closure { return static fn(): string => __CLASS__; } } return {$classB}::make();");

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});

it('rejects a method owner changed on disk after the runtime closure was created', function (): void {
    $file = sys_get_temp_dir() . '/componenta_method_owner_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $class = 'ComponentaVarExportMethodOwner' . $suffix;

    try {
        file_put_contents($file, "<?php class {$class} { public static function makeA(): \\Closure { return static fn(): string => __METHOD__; } } return {$class}::makeA();");
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents($file, "<?php class {$class} { public static function makeB(): \\Closure { return static fn(): string => __METHOD__; } } return {$class}::makeB();");

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});

it('rejects a named-function namespace changed on disk after closure creation', function (): void {
    $file = sys_get_temp_dir() . '/componenta_function_owner_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $old = 'ComponentaVarExportOld' . $suffix;
    $new = 'ComponentaVarExportNew' . $suffix;

    try {
        file_put_contents($file, "<?php namespace {$old}; function make(): \\Closure { return static fn(): string => __NAMESPACE__; } return make();");
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents($file, "<?php namespace {$new}; function make(): \\Closure { return static fn(): string => __NAMESPACE__; } return make();");

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});
