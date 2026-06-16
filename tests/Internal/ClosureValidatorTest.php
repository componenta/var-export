<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Internal;

use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Internal\ClosureValidator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the validator's public contract:
 *
 * - Rejects closures that cannot be exported (bound $this).
 * - In inline mode, rejects closures capturing non-scalar values,
 *   and reports the offending variables keyed by name with their type.
 * - Keeps scalar/array inline captures silent.
 *
 * Trivial getters/passthroughs to PHP reflection are not tested here -
 * they carry no behavior of this class.
 */
final class ClosureValidatorTest extends TestCase
{
    private ClosureValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ClosureValidator();
    }

    public function testValidateThrowsWhenClosureIsBoundToThis(): void
    {
        $object = new class {
            public function make(): \Closure
            {
                return function (): self {
                    return $this;
                };
            }
        };

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('$this');

        $this->validator->validate($object->make(), ClosureUseMode::Preserve);
    }

    public function testValidateWithInlineModeRejectsObjectCaptures(): void
    {
        $captured = new \stdClass();
        $closure = static function () use ($captured) {
            return $captured;
        };

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('Cannot inline');

        $this->validator->validate($closure, ClosureUseMode::Inline);
    }

    public function testFindUnexportableVariablesReportsObjectsByName(): void
    {
        $variables = [
            'a' => 1,
            'b' => 'hello',
            'obj' => new \stdClass(),
        ];

        $unexportable = $this->validator->findUnexportableVariables($variables);

        self::assertArrayHasKey('obj', $unexportable);
        self::assertArrayNotHasKey('a', $unexportable);
        self::assertArrayNotHasKey('b', $unexportable);
        self::assertStringContainsString('stdClass', $unexportable['obj']);
    }

    public function testFindUnexportableVariablesReportsResourcesByName(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $unexportable = $this->validator->findUnexportableVariables(['file' => $resource]);
        } finally {
            fclose($resource);
        }

        self::assertArrayHasKey('file', $unexportable);
        self::assertStringContainsString('resource', $unexportable['file']);
    }

    public function testFindUnexportableVariablesReportsObjectsNestedInArrays(): void
    {
        $variables = [
            'items' => [1, 2, new \stdClass()],
        ];

        $unexportable = $this->validator->findUnexportableVariables($variables);

        self::assertArrayHasKey('items', $unexportable);
        self::assertStringContainsString('stdClass', $unexportable['items']);
    }

    public function testFindUnexportableVariablesRejectsClosureCaptures(): void
    {
        // Nested closures are explicitly unsupported in inline mode: their
        // recursive export would require another pass through the whole
        // pipeline with a different reflection, which is brittle and not a
        // documented use case.
        $unexportable = $this->validator->findUnexportableVariables([
            'fn' => static fn() => 42,
        ]);

        self::assertArrayHasKey('fn', $unexportable);
        self::assertStringContainsString('Closure', $unexportable['fn']);
    }

    public function testFindUnexportableVariablesAcceptsArraysOfScalars(): void
    {
        $unexportable = $this->validator->findUnexportableVariables([
            'items' => [1, 2, 3, 'four', true, null, 5.5],
        ]);

        self::assertSame([], $unexportable);
    }

    public function testFindUnexportableVariablesAcceptsEmptyMap(): void
    {
        self::assertSame([], $this->validator->findUnexportableVariables([]));
    }
}
