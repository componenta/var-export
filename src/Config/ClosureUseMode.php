<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

/**
 * Defines how captured closure variables (use clause) are handled.
 */
enum ClosureUseMode
{
    /**
     * Keep captures as variables (`use ($a, $b)`).
     *
     * The generated expression is source-oriented: those variables must exist
     * in the scope where the generated closure expression is evaluated.
     */
    case Preserve;

    /**
     * Freeze supported capture values into a static creator expression.
     *
     * Supported capture values are null, scalars, enum cases and nested arrays
     * of those values without PHP references. Object instances, resources,
     * nested Closure objects and by-reference captures are rejected rather than
     * silently changing their identity/reference semantics.
     */
    case Inline;
}
