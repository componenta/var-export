<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

use Throwable;

/**
 * Base interface for all VarExport exceptions.
 *
 * Allows catching all library exceptions with a single catch block
 * while maintaining proper exception hierarchy.
 */
interface ExceptionInterface extends Throwable
{
}
