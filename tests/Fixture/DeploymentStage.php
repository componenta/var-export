<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

enum DeploymentStage: string
{
    case Production = 'production';
    case Testing = 'testing';
}
