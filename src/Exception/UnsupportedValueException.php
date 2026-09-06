<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

final class UnsupportedValueException extends ExportException
{
    public static function forValue(mixed $value): self
    {
        $type = is_resource($value)
            ? sprintf('resource (%s)', get_resource_type($value))
            : get_debug_type($value);

        return new self(
            sprintf('No exporter supports a value of type "%s".', $type),
            ['type' => $type],
        );
    }
}
