<?php

declare(strict_types=1);

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;

it('rejects a stale closure attribute expression without executing it', function (): void {
    $file = sys_get_temp_dir() . '/componenta_unverifiable_closure_attribute_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'ComponentaUnverifiableClosureAttribute' . $suffix;
    $payload = 'ComponentaUnverifiableClosurePayload' . $suffix;

    try {
        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attribute} { public function __construct(public mixed \$value) {} } class {$payload} { public static int \$constructed = 0; public function __construct() { ++self::\$constructed; } } return #[{$attribute}(1)] static fn(): int => 1;",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attribute} { public function __construct(public mixed \$value) {} } class {$payload} { public static int \$constructed = 0; public function __construct() { ++self::\$constructed; } } return #[{$attribute}(new {$payload}())] static fn(): int => 1;",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'Cannot verify closure or parameter attribute arguments')
            ->and($payload::$constructed)->toBe(0);
    } finally {
        @unlink($file);
    }
});

it('rejects a stale parameter attribute expression without executing it', function (): void {
    $file = sys_get_temp_dir() . '/componenta_unverifiable_parameter_attribute_' . bin2hex(random_bytes(6)) . '.php';
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'ComponentaUnverifiableParameterAttribute' . $suffix;
    $payload = 'ComponentaUnverifiableParameterPayload' . $suffix;

    try {
        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_PARAMETER)] class {$attribute} { public function __construct(public mixed \$value) {} } class {$payload} { public static int \$constructed = 0; public function __construct() { ++self::\$constructed; } } return static fn(#[{$attribute}(1)] int \$value): int => \$value;",
        );
        /** @var Closure $closure */
        $closure = require $file;

        file_put_contents(
            $file,
            "<?php #[\\Attribute(\\Attribute::TARGET_PARAMETER)] class {$attribute} { public function __construct(public mixed \$value) {} } class {$payload} { public static int \$constructed = 0; public function __construct() { ++self::\$constructed; } } return static fn(#[{$attribute}(new {$payload}())] int \$value): int => \$value;",
        );

        expect(fn() => Export::closure($closure))
            ->toThrow(ClosureExportException::class, 'Cannot verify closure or parameter attribute arguments')
            ->and($payload::$constructed)->toBe(0);
    } finally {
        @unlink($file);
    }
});
