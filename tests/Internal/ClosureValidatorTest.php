<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Internal;

use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Internal\ClosureValidator;
use PHPUnit\Framework\TestCase;

class ValidatorBaseScope
{
    public static function closure(): \Closure
    {
        return static fn(): string => static::class;
    }
}

final class ValidatorChildScope extends ValidatorBaseScope
{
}

final class ValidatorSafeScope
{
    public static function closure(): \Closure
    {
        return static fn(): string => self::class;
    }
}

final class ClosureValidatorTest extends TestCase
{
    public function testAcceptsUnboundClosureWithReadableSource(): void
    {
        $reflection = (new ClosureValidator())->validate(static fn(): int => 42);

        self::assertNotFalse($reflection->getFileName());
    }

    public function testRejectsClosureBoundToThis(): void
    {
        $object = new class {
            public function closure(): \Closure
            {
                return function (): self {
                    return $this;
                };
            }
        };

        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('$this');
        (new ClosureValidator())->validate($object->closure());
    }

    public function testAcceptsClassScopeWhenLexicalAndCalledClassMatch(): void
    {
        $reflection = (new ClosureValidator())->validate(ValidatorSafeScope::closure());

        self::assertSame(ValidatorSafeScope::class, $reflection->getClosureScopeClass()?->getName());
    }

    public function testRejectsLateStaticBindingScopeMismatch(): void
    {
        $this->expectException(ClosureExportException::class);
        $this->expectExceptionMessage('lexical class');
        (new ClosureValidator())->validate(ValidatorChildScope::closure());
    }
}
