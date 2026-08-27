<?php

declare(strict_types=1);

use Componenta\VarExport\Export;

it('exports an arrow function whose attribute is on the previous line', function (): void {
    $file = sys_get_temp_dir() . '/componenta_multiline_arrow_attribute_' . bin2hex(random_bytes(6)) . '.php';
    $attribute = 'ComponentaMultilineArrowAttribute' . bin2hex(random_bytes(5));

    try {
        file_put_contents(
            $file,
            "<?php\n#[\\Attribute(\\Attribute::TARGET_FUNCTION)]\nclass {$attribute} {}\nreturn\n#[{$attribute}]\nstatic fn(): int => 42;\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        /** @var Closure $restored */
        $restored = eval('return ' . Export::closure($closure) . ';');

        expect($restored())->toBe(42);
    } finally {
        @unlink($file);
    }
});

it('exports a closure whose multiline attribute precedes the declaration', function (): void {
    $file = sys_get_temp_dir() . '/componenta_multiline_closure_attribute_' . bin2hex(random_bytes(6)) . '.php';
    $attribute = 'ComponentaMultilineClosureAttribute' . bin2hex(random_bytes(5));

    try {
        file_put_contents(
            $file,
            "<?php\n#[\\Attribute(\\Attribute::TARGET_FUNCTION)]\nclass {$attribute} { public function __construct(public int \$value) {} }\nreturn\n#[{$attribute}(\n    42,\n)]\nstatic function (): int { return 42; };\n",
        );
        /** @var Closure $closure */
        $closure = require $file;

        /** @var Closure $restored */
        $restored = eval('return ' . Export::closure($closure) . ';');

        expect($restored())->toBe(42);
    } finally {
        @unlink($file);
    }
});
