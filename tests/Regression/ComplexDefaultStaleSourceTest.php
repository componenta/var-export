<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects unverifiable parameter defaults instead of accepting stale source', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_complex_default_' . bin2hex(random_bytes(6)) . '.php';

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
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});
