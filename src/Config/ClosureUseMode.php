<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

/**
 * Determines how closure "use" variables are handled during export.
 */
enum ClosureUseMode: string
{
    /**
     * Preserve the use() clause as-is.
     *
     * The exported closure will contain `use ($var1, $var2, ...)` exactly
     * as it appears in the source. The variables will need to be defined
     * in the scope where the exported code is executed.
     *
     * Example:
     *   Input:  function() use ($value) { return $value * 2; }
     *   Output: function() use ($value) { return $value * 2; }
     */
    case Preserve = 'preserve';

    /**
     * Inline the values of use() variables into the closure body.
     *
     * The use() clause is removed and variables are replaced with their
     * actual values at export time. This makes the closure self-contained.
     *
     * Only works when all captured values are exportable (scalars, arrays
     * of scalars). Nested closures in use() variables are NOT supported
     * with inline mode.
     *
     * Throws ClosureExportException if any value cannot be exported.
     *
     * Example:
     *   Input:  $value = 42; $fn = function() use ($value) { return $value * 2; }
     *   Output: function() { return 42 * 2; }
     */
    case Inline = 'inline';
}
