<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

enum SourcePathPolicy
{
    /** Freeze __FILE__/__DIR__ to the absolute source path. */
    case AbsoluteBuildPath;

    /** Reject __FILE__/__DIR__ because they would make the artifact source-root dependent. */
    case Reject;
}
