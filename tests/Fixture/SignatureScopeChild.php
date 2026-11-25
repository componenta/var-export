<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Closure;

final class SignatureScopeChild extends SignatureScopeBase
{
    protected const int DEFAULT_VALUE = 42;

    public static function scopedDefaults(): Closure
    {
        return static fn (int $self = self::DEFAULT_VALUE, int $parent = parent::DEFAULT_VALUE): array => [$self, $parent];
    }

    public static function nullable(): Closure
    {
        return static fn (?int $value = null): ?int => $value;
    }

    public static function selfType(): Closure
    {
        return static fn (self $value): self => $value;
    }

    public static function parentType(): Closure
    {
        return static fn (parent $value): parent => $value;
    }
}
