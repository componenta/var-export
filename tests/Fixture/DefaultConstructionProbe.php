<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

final class DefaultConstructionProbe
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}
