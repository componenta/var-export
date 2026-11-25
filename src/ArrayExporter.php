<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ArrayExportException;
use Fiber;
use ReflectionReference;

final class ArrayExporter implements VarExportInterface, VarExportAwareInterface
{
    private const int MAX_DEPTH = 256;

    private ?VarExport $varExport = null;
    private readonly ScalarExporter $keyExporter;

    /** @var array<int, int> */
    private array $activeDepths = [];

    public function __construct()
    {
        $this->keyExporter = new ScalarExporter();
    }

    public function withVarExport(VarExport $varExport): static
    {
        $exporter = clone $this;
        $exporter->varExport = $varExport;
        $exporter->activeDepths = [];

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

        $fiber = Fiber::getCurrent();
        $context = $fiber === null ? 0 : spl_object_id($fiber);
        $depth = $this->activeDepths[$context] ?? 0;
        if ($depth >= self::MAX_DEPTH) {
            throw ArrayExportException::depth(self::MAX_DEPTH);
        }

        $this->activeDepths[$context] = $depth + 1;
        try {
            return $this->exportArray($var, $varExport);
        } finally {
            if ($depth === 0) {
                unset($this->activeDepths[$context]);
            } else {
                $this->activeDepths[$context] = $depth;
            }
        }
    }

    /**
     * @param array<mixed> $array
     */
    private function exportArray(array $array, VarExport $varExport): string
    {
        if (key($array) !== array_key_first($array)) {
            throw ArrayExportException::pointer([]);
        }

        $isList = array_is_list($array);
        $literalIndex = $isList ? count($array) : $this->literalAppendIndex($array);

        if ($this->nextAppendIndex($array) !== $literalIndex) {
            throw ArrayExportException::appendIndex([]);
        }

        if ($array === []) {
            return '[]';
        }

        $itemIndent = '    ';
        $entries = [];

        foreach ($array as $key => $value) {
            if (ReflectionReference::fromArrayElement($array, $key) !== null) {
                throw ArrayExportException::reference([$key]);
            }

            try {
                $expression = $varExport->export($value);
            } catch (ArrayExportException $error) {
                if (!is_array($value)) {
                    throw $error;
                }

                throw $error->withPrependedKey($key);
            }

            $expression = $this->indentExpression($expression, $itemIndent);

            $keyExpression = $isList
                ? ''
                : $this->exportKey($key) . ' => ';
            $entries[] = $itemIndent . $keyExpression . $expression;
        }

        return "[\n"
            . implode(",\n", $entries)
            . ",\n"
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

    private function indentExpression(string $expression, string $indent): string
    {
        if (!str_contains($expression, "\n")) {
            return $expression;
        }

        $result = '';

        foreach (token_get_all('<?php ' . $expression) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_OPEN_TAG) {
                    continue;
                }

                if (in_array($id, [
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                    T_START_HEREDOC,
                    T_END_HEREDOC,
                ], true)) {
                    $result .= $text;

                    continue;
                }
            } else {
                $text = $token;
            }

            $result .= str_replace("\n", "\n" . $indent, $text);
        }

        return $result;
    }
}
