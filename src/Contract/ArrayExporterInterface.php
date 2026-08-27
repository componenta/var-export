<?php

declare(strict_types=1);

namespace Componenta\VarExport\Contract;

interface ArrayExporterInterface
{
    /**
     * @param array<mixed> $array
     * @throws ExceptionInterface
     */
    public function export(array $array): string;
}
