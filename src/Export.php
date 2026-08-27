<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Contract\ExceptionInterface;

/** Stable convenience facade for one-off exports. */
final class Export
{
    private function __construct()
    {
    }

    /** @throws ExceptionInterface */
    public static function var(mixed $var, ?ExportConfig $config = null): string
    {
        return self::createExporter($config)->export($var);
    }

    /** @throws ExceptionInterface */
    public static function pretty(mixed $var, ?ExportConfig $config = null): string
    {
        $config = $config?->withMode(FormatterMode::Pretty) ?? ExportConfig::pretty();

        return self::createExporter($config)->export($var);
    }

    /** @throws ExceptionInterface */
    public static function statement(mixed $var, ?ExportConfig $config = null): string
    {
        return self::createExporter($config)->export($var) . ';';
    }

    /**
     * @param array<mixed> $array
     * @throws ExceptionInterface
     */
    public static function array(array $array, ?ExportConfig $config = null): string
    {
        return self::var($array, $config);
    }

    /** @throws ExceptionInterface */
    public static function closure(Closure $closure, ?ExportConfig $config = null): string
    {
        return self::var($closure, $config);
    }

    private static function createExporter(?ExportConfig $config): VarExporter
    {
        return new VarExporter($config ?? new ExportConfig());
    }
}
