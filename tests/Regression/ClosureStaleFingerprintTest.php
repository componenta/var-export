<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects changed arrow-function captures after the runtime closure was created', function (): void {
    $file = sys_get_temp_dir() . '/componenta_arrow_capture_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents($file, '<?php $a = 1; return static fn(): int => $a;');
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents($file, '<?php $b = 1; return static fn(): int => $b;');

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});

it('rejects a generator body introduced after the runtime closure was created', function (): void {
    $file = sys_get_temp_dir() . '/componenta_generator_stale_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents($file, '<?php return static function(): iterable { return []; };');
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents($file, '<?php return static function(): iterable { yield 1; };');

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});

it('rejects static local state introduced only in stale source', function (): void {
    $file = sys_get_temp_dir() . '/componenta_static_local_stale_' . bin2hex(random_bytes(6)) . '.php';

    try {
        file_put_contents($file, '<?php return static function(): int { return 1; };');
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents($file, '<?php return static function(): int { static $counter = 0; return ++$counter; };');

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});

it('rejects changed closure attributes after the runtime closure was created', function (): void {
    $file = sys_get_temp_dir() . '/componenta_closure_attribute_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $attributeA = 'ComponentaClosureAttrA' . $suffix;
    $attributeB = 'ComponentaClosureAttrB' . $suffix;

    try {
        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attributeA} {} #[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attributeB} {} return #[{$attributeA}] static fn(): int => 1;",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attributeA} {} #[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attributeB} {} return #[{$attributeB}] static fn(): int => 1;",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});

it('rejects changed parameter attributes after the runtime closure was created', function (): void {
    $file = sys_get_temp_dir() . '/componenta_parameter_attribute_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $attributeA = 'ComponentaParameterAttrA' . $suffix;
    $attributeB = 'ComponentaParameterAttrB' . $suffix;

    try {
        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_PARAMETER)] class {$attributeA} {} #[\\Attribute(\\Attribute::TARGET_PARAMETER)] class {$attributeB} {} return static fn(#[{$attributeA}] int \$value): int => \$value;",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_PARAMETER)] class {$attributeA} {} #[\\Attribute(\\Attribute::TARGET_PARAMETER)] class {$attributeB} {} return static fn(#[{$attributeB}] int \$value): int => \$value;",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'no longer matches');
    } finally {
        @unlink($file);
    }
});
