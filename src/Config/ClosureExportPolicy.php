<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

enum ClosureExportPolicy
{
    /**
     * Preserve the runtime behavior of the source closure for the current
     * source/runtime environment. Source-bound constructs are allowed.
     */
    case SourceBound;

    /**
     * Require an expression that is portable across build/runtime locations.
     * Source-bound constructs are rejected instead of being silently frozen.
     */
    case PortableExpression;
}
