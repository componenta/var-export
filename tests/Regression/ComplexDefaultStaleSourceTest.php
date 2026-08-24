<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

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
