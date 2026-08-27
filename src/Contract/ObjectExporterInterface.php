<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

interface ObjectExporterInterface
{
    /** @throws ExceptionInterface */
    public function export(object $object): string;
}
