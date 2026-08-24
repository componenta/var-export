<?php

declare(strict_types=1);

use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

final class PortableMagicFunctionFixture
{
    public static function make(): Closure
    {
        return static fn(): string => __FUNCTION__;
    }
}

it('qualifies non-finite float constants against namespace shadowing', function (): void {
    $file = sys_get_temp_dir() . '/componenta_non_finite_' . bin2hex(random_bytes(6)) . '.php';
    $code = Export::var([INF, -INF, NAN]);

    try {
        file_put_contents(
            $file,
            "<?php\nnamespace Componenta\\VarExport\\Tests\\Regression\\ShadowedNonFinite;\n"
            . "const INF = 123;\nconst NAN = 456;\nreturn {$code};\n",
        );

        $restored = require $file;

        expect(is_infinite($restored[0]))->toBeTrue()
            ->and($restored[0] > 0)->toBeTrue()
            ->and(is_infinite($restored[1]))->toBeTrue()
            ->and($restored[1] < 0)->toBeTrue()
            ->and(is_nan($restored[2]))->toBeTrue();
    } finally {
        @unlink($file);
    }
});

it('rejects include and eval in source-bound closures because relocation changes their context', function (): void {
    $include = static fn(): mixed => include __FILE__;
    $eval = static fn(): mixed => eval('return __FILE__;');

    expect(fn() => Export::closure($include))
        ->toThrow(ClosureExportException::class, 'include/require')
        ->and(fn() => Export::closure($eval))
        ->toThrow(ClosureExportException::class, 'eval()');
});

it('rejects path-bearing top-level closure names in portable mode', function (): void {
    $file = sys_get_temp_dir() . '/componenta_magic_function_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents($file, "<?php\nreturn static fn(): string => __FUNCTION__;\n");
        /** @var Closure $closure */
        $closure = require $file;
        $config = new ExportConfig(closureExportPolicy: ClosureExportPolicy::PortableExpression);

        expect(fn() => Export::closure($closure, $config))
            ->toThrow(ClosureExportException::class, '__FUNCTION__');
    } finally {
        @unlink($file);
    }
});

it('keeps path-independent class closure names portable', function (): void {
    $closure = PortableMagicFunctionFixture::make();
    $config = new ExportConfig(closureExportPolicy: ClosureExportPolicy::PortableExpression);
    $restored = eval('return ' . Export::closure($closure, $config) . ';');

    expect($restored())->toBe($closure());
});

it('does not let an unverifiable same-line candidate hide an exact candidate', function (): void {
    $file = sys_get_temp_dir() . '/componenta_same_line_defaults_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents(
            $file,
            '<?php return [static fn($value = new \\stdClass()): mixed => $value, static fn($value = 1): mixed => $value];',
        );
        /** @var array{0: Closure, 1: Closure} $closures */
        $closures = require $file;

        $restored = eval('return ' . Export::closure($closures[1]) . ';');

        expect($restored())->toBe(1);
    } finally {
        @unlink($file);
    }
});
