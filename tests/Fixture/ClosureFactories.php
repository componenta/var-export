<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

const LOCAL_CONSTANT = 'fixture-constant';

function local_function(): string
{
    return 'fixture-function';
}

function captureFactory(int $value): Closure
{
    return static function () use ($value): int {
        ++$value;

        return $value;
    };
}

function arrowCaptureFactory(int $value): Closure
{
    return static fn(): int => $value;
}

function nestedCaptureFactory(string $value): Closure
{
    return static function () use ($value): Closure {
        return static fn(): string => $value;
    };
}

function localSymbolClosure(): Closure
{
    return static fn(): array => [local_function(), LOCAL_CONSTANT];
}

function magicClosure(): Closure
{
    return static fn(): array => [
        __FILE__,
        __DIR__,
        __NAMESPACE__,
        __LINE__,
        __CLASS__,
        __METHOD__,
        __FUNCTION__,
        __TRAIT__,
    ];
}

final class ScopedClosureFixture
{
    private const string SECRET = 'scoped-secret';

    public static function make(): Closure
    {
        return static fn(): array => [
            self::SECRET,
            __CLASS__,
            __METHOD__,
            __FUNCTION__,
        ];
    }
}

class LateStaticBase
{
    public static function make(): Closure
    {
        return static fn(): string => static::class;
    }
}

final class LateStaticChild extends LateStaticBase
{
}

trait ClosureTraitFixture
{
    public static function makeTraitClosure(): Closure
    {
        return static fn(): array => [__TRAIT__, __CLASS__];
    }
}

final class TraitConsumer
{
    use ClosureTraitFixture;
}

final readonly class SupportedValueObject
{
    public function __construct(
        public int $id,
        public array $data,
    ) {
    }
}

final readonly class NonPromotedValueObject
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = strtoupper($value);
    }
}

final readonly class PrivateConstructorValueObject
{
    private function __construct(public string $value)
    {
    }

    public static function make(string $value): self
    {
        return new self($value);
    }
}

final readonly class ByReferenceConstructorValueObject
{
    public string $value;

    public function __construct(string &$value)
    {
        $this->value = $value;
    }
}
