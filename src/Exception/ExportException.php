<?php

declare(strict_types=1);

namespace Componenta\VarExport\Exception;

use Componenta\VarExport\Contract\ExceptionInterface;
use Exception;
use Throwable;

class ExportException extends Exception implements ExceptionInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?string $sourceFile = null,
        ?int $sourceLine = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);

        if ($sourceFile !== null) {
            $this->file = $sourceFile;
        }

        if ($sourceLine !== null) {
            $this->line = $sourceLine;
        }
    }

    public static function unsupportedType(mixed $value): self
    {
        $type = get_debug_type($value);

        return new self(
            sprintf('Cannot export value of type "%s".', $type),
            ['type' => $type],
        );
    }

    public static function unexportableObject(object $object): self
    {
        return new self(
            sprintf(
                'Object of type "%s" is not reconstructable as an enum or supported readonly value object.',
                $object::class,
            ),
            ['class' => $object::class],
        );
    }

    public static function resourceNotExportable(mixed $resource): self
    {
        $type = is_resource($resource)
            ? get_resource_type($resource)
            : (is_object($resource) ? $resource::class : 'closed resource');

        return new self(
            sprintf('Resource of type "%s" cannot be represented as executable PHP source.', $type),
            ['resource_type' => $type],
        );
    }

    public static function objectCycle(string $class, int $depth): self
    {
        return new self(
            sprintf('Object graph cycle or repeated identity detected while exporting "%s" at depth %d.', $class, $depth),
            ['class' => $class, 'depth' => $depth],
        );
    }

    /** @param array<int|string> $keyPath */
    public static function formatKeyPath(array $keyPath): string
    {
        if ($keyPath === []) {
            return 'root';
        }

        $parts = [];
        foreach ($keyPath as $key) {
            $parts[] = is_int($key)
                ? sprintf('[%d]', $key)
                : sprintf('[%s]', var_export($key, true));
        }

        return '$array' . implode('', $parts);
    }
}
