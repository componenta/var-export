<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;

function var_export_string(mixed $var, ?ExportConfig $config = null, bool $pretty = false): string
{
    return $pretty ? Export::pretty($var, $config) : Export::var($var, $config);
}

function var_export_pretty(mixed $var, ?ExportConfig $config = null): string
{
    return Export::pretty($var, $config);
}

/** @param array<mixed> $array */
function array_export(array $array, ?ExportConfig $config = null, bool $pretty = false): string
{
    return $pretty ? Export::pretty($array, $config) : Export::array($array, $config);
}

function closure_export(Closure $closure, ?ExportConfig $config = null, bool $pretty = false): string
{
    return $pretty ? Export::pretty($closure, $config) : Export::closure($closure, $config);
}
