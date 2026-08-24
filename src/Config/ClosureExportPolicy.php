<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

enum ClosureExportPolicy
{
    /**
     * Preserve source/runtime semantics that can be frozen into a standalone
     * expression, such as magic constants and namespace symbol resolution.
     *
     * Execution constructs whose meaning depends on the generated artifact
     * location or scope (for example include/require and eval()) are rejected
     * in every policy because expression-only relocation would change behavior.
     */
    case SourceBound;

    /**
     * Require an expression that is portable across build/runtime locations.
     * Source-location-dependent constructs are rejected instead of being
     * silently frozen into the artifact.
     */
    case PortableExpression;
}
