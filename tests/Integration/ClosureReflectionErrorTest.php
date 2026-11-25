<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;
use Error;
use LogicException;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\overwriteFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('reports an inaccessible runtime parameter default through the export exception contract', function (): void {
    $constant = 'COMPONENTA_VAR_EXPORT_MISSING_' . strtoupper(bin2hex(random_bytes(8)));
    $path = createTemporaryPhpFile(
        'componenta-unresolved-default-',
        '<?php return static fn (array $value = [' . $constant . ' + 1]): array => $value;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, '<?php return static fn (array $value = [1]): array => $value;');

        try {
            VarExport::withDefaults()->export($closure);
            throw new LogicException('Expected the export to reject the modified source.');
        } catch (ExportException $error) {
            expect($error->getMessage())->toContain('runtime default')
                ->and($error->getPrevious())->toBeInstanceOf(Error::class)
                ->and($error->getFile())->toBe($path);
        }
    } finally {
        unlink($path);
    }
});

it('reports inaccessible runtime attribute arguments through the export exception contract', function (): void {
    $constant = 'COMPONENTA_VAR_EXPORT_MISSING_' . strtoupper(bin2hex(random_bytes(8)));
    $attribute = 'ComponentaRuntimeArgument' . bin2hex(random_bytes(8));
    $declaration = '#[\\Attribute(\\Attribute::TARGET_FUNCTION)] class ' . $attribute
        . ' { public function __construct(public array $value) {} } ';
    $path = createTemporaryPhpFile(
        'componenta-unresolved-attribute-',
        '<?php ' . $declaration . 'return #[' . $attribute . '([' . $constant . ' + 1])] static fn (): int => 42;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, '<?php ' . $declaration . 'return #[' . $attribute . '([1])] static fn (): int => 42;');

        try {
            VarExport::withDefaults()->export($closure);
            throw new LogicException('Expected the export to reject the modified source.');
        } catch (ExportException $error) {
            expect($error->getMessage())->toContain('runtime arguments')
                ->and($error->getPrevious())->toBeInstanceOf(Error::class)
                ->and($error->getFile())->toBe($path);
        }
    } finally {
        unlink($path);
    }
});
