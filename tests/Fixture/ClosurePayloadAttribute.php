<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION)]
final readonly class ClosurePayloadAttribute
{
    public function __construct(public mixed $value)
    {
    }
}
