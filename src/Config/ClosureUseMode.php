<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

/**
 * Determines how lexical closure captures are represented.
 */
enum ClosureUseMode: string
{
    /**
     * Keep the original use()/implicit arrow capture syntax.
     *
     * The generated closure therefore still depends on variables in the scope
     * where the generated expression is evaluated.
     */
    case Preserve = 'preserve';

    /**
     * Freeze captured scalar/array values into a self-contained creator
     * expression while keeping the original closure body and variable
     * semantics intact.
     *
     * By-reference captures and arrays containing references are rejected.
     */
    case Inline = 'inline';
}
