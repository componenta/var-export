<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects stale source when a closure capture changes reference mode', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_capture_ref_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents(
            $file,
            "<?php\n\$value = 1;\nreturn static function () use (\$value): int { return \$value; };\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php\n\$value = 1;\nreturn static function () use (&\$value): int { return \$value; };\n",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});
