<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ArrayExportException;
use Componenta\VarExport\Internal\ContextualExporterInterface;
use Componenta\VarExport\Internal\ExportContext;
use Componenta\VarExport\Internal\ExpressionIndentation;
use ReflectionReference;

final class ArrayExporter implements ContextualExporterInterface, VarExportAwareInterface
{
    private const int MAX_DEPTH = 256;

    private ?VarExport $varExport = null;
    private readonly ScalarExporter $keyExporter;

    public function __construct()
    {
        $this->keyExporter = new ScalarExporter();
    }

    public function withVarExport(VarExport $varExport): static
    {
        $exporter = clone $this;
        $exporter->varExport = $varExport;

        return $exporter;
    }

    public function export(mixed $var): ?string
    {
        if (!is_array($var)) {
            return null;
        }

        $varExport = $this->varExport;
        if ($varExport === null) {
            throw ExportException::exporterIsNotBound(self::class);
        }

        $context = new ExportContext(
            static function (mixed $value, int $depth, array $path) use ($varExport): string {
                try {
                    $expression = $varExport->export($value);
                } catch (ArrayExportException $error) {
                    if (!is_array($value)) {
                        throw $error;
                    }

                    throw $error->withPrependedPath($path);
                }

                return ExpressionIndentation::atDepth($expression, $depth);
            },
        );

        return $this->exportInContext($var, $context);
    }

    /** @internal */
    public function exportInContext(mixed $var, ExportContext $context): ?string
    {
        if (!is_array($var)) {
            return null;
        }

        if ($this->varExport === null) {
            throw ExportException::exporterIsNotBound(self::class);
        }

        if ($context->depth >= self::MAX_DEPTH) {
            throw ArrayExportException::depth(self::MAX_DEPTH, $context->path);
        }

        $array = $var;
        if (key($array) !== array_key_first($array)) {
            throw ArrayExportException::pointer($context->path);
        }

        $isList = array_is_list($array);
        $literalIndex = $isList ? count($array) : $this->literalAppendIndex($array);

        if ($this->nextAppendIndex($array) !== $literalIndex) {
            throw ArrayExportException::appendIndex($context->path);
        }

        if ($array === []) {
            return '[]';
        }

        $itemIndent = str_repeat(' ', ($context->depth + 1) * 4);
        $closingIndent = str_repeat(' ', $context->depth * 4);
        $entries = [];

        foreach ($array as $key => $value) {
            if (ReflectionReference::fromArrayElement($array, $key) !== null) {
                throw ArrayExportException::reference([...$context->path, $key]);
            }

            $expression = $context->exportChild($value, $key);

            $keyExpression = $isList
                ? ''
                : $this->exportKey($key) . ' => ';
            $entries[] = $itemIndent . $keyExpression . $expression;
        }

        return "[\n"
            . implode(",\n", $entries)
            . ",\n"
            . $closingIndent
            . ']';
    }

    /** @param array<mixed> $array */
    private function nextAppendIndex(array $array): ?int
    {
        if (array_key_exists(PHP_INT_MAX, $array)) {
            return null;
        }

        $array[] = null;

        return (int) array_key_last($array);
    }

    /** @param array<mixed> $array */
    private function literalAppendIndex(array $array): ?int
    {
        $maximum = null;
        foreach ($array as $key => $_) {
            if (is_int($key) && ($maximum === null || $key > $maximum)) {
                $maximum = $key;
            }
        }

        if ($maximum === PHP_INT_MAX) {
            return null;
        }

        return $maximum === null ? 0 : $maximum + 1;
    }

    private function exportKey(int|string $key): string
    {
        return $this->keyExporter->export($key)
            ?? throw new \LogicException('Array keys are always supported by ScalarExporter.');
    }
}
