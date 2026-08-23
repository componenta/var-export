<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

enum FormatterMode: string
{
    case Standard = 'standard';
    case Pretty = 'pretty';
}
