<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION)]
final readonly class ClosureMarker
{
    public function __construct(public string $name)
    {
    }
}
