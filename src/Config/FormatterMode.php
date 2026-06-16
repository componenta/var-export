<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

/**
 * Output formatting mode.
 */
enum FormatterMode: string
{
    /**
     * Compact single-line output.
     */
    case Standard = 'standard';

    /**
     * Multi-line output with indentation.
     */
    case Pretty = 'pretty';
}
