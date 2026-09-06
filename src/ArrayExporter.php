<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Componenta\VarExport\Exception\ExportException;
use ReflectionReference;

final class ArrayExporter implements VarExportInterface, VarExportAwareInterface
{
    private ?VarExport $varExport = null;

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

        return $this->exportArray($var, $varExport);
    }

    /**
     * @param array<mixed> $array
     * @param list<int|string> $path
     */
    private function exportArray(
        array $array,
        VarExport $varExport,
        int $depth = 0,
        array $path = [],
    ): string {
        if ($array === []) {
            return '[]';
        }

        $isList = array_is_list($array);
        $itemIndent = str_repeat(' ', ($depth + 1) * 4);
        $closingIndent = str_repeat(' ', $depth * 4);
        $entries = [];

        foreach ($array as $key => $value) {
            $itemPath = [...$path, $key];

            if (ReflectionReference::fromArrayElement($array, $key) !== null) {
                throw ExportException::arrayReference($itemPath);
            }

            if (is_array($value)) {
                $expression = $this->exportArray($value, $varExport, $depth + 1, $itemPath);
            } else {
                $expression = $varExport->export($value);
                $expression = $this->indentExpression($expression, $itemIndent);
            }

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

    private function exportKey(int|string $key): string
    {
        return new ScalarExporter()->export($key)
            ?? throw new \LogicException('Array keys are always supported by ScalarExporter.');
    }

    private function indentExpression(string $expression, string $indent): string
    {
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
