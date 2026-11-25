<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use Componenta\VarExport\VarExport;
use LogicException;

use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('restores a named closure scope or reports that it is unavailable in a fresh process', function (string $environment, string $expected): void {
    $class = 'ComponentaRestoredScope' . bin2hex(random_bytes(8));
    $provider = createTemporaryPhpFile(
        'componenta-scope-provider-',
        '<?php declare(strict_types=1); class ' . $class
        . ' { public static function make(): \Closure { return static fn (): int => 42; } } return ' . $class . '::make();',
    );
    $artifact = null;
    $runner = null;

    try {
        $original = requireClosureFile($provider);
        $expression = VarExport::withDefaults()->export($original);
        $artifact = createTemporaryPhpFile(
            'componenta-scope-artifact-',
            '<?php declare(strict_types=1); return ' . $expression . ';',
        );

        $setup = match ($environment) {
            'available' => 'require ' . var_export($provider, true) . ';',
            'internal' => 'class_alias(\DateTime::class, ' . var_export($class, true) . ');',
            default => '',
        };
        $runner = createTemporaryPhpFile(
            'componenta-scope-runner-',
            '<?php declare(strict_types=1); require '
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; '
            . 'set_error_handler(static function (int $severity, string $message): never { '
            . 'throw new \ErrorException($message, 0, $severity); }); '
            . $setup . ' try { $closure = require ' . var_export($artifact, true)
            . '; if (!$closure instanceof \Closure) { throw new \LogicException("Expected a closure."); } '
            . 'echo $closure(); } catch (\Componenta\VarExport\Exception\ExportException $error) { '
            . 'echo str_contains($error->getMessage(), "Cannot restore closure class scope") ? "rejected" : "unexpected"; }',
        );

        $process = proc_open([PHP_BINARY, $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new LogicException('Unable to start the PHP scope test process.');
        }

        try {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        }

        expect($status)->toBe(0)
            ->and($stderr)->toBe('')
            ->and($stdout)->toBe($expected);
    } finally {
        unlink($provider);
        if ($artifact !== null) {
            unlink($artifact);
        }
        if ($runner !== null) {
            unlink($runner);
        }
    }
})->with([
    'available user-defined scope' => ['available', '42'],
    'unavailable scope' => ['missing', 'rejected'],
    'incompatible internal scope' => ['internal', 'rejected'],
]);
