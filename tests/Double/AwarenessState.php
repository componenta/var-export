<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Double;

use Componenta\VarExport\VarExport;
use Componenta\VarExport\VarExportInterface;

final class AwarenessState
{
    public ?VarExport $boundTo = null;
    public ?VarExportInterface $exportedBy = null;
}
