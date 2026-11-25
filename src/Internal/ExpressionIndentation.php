<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

/** @internal */
final class ExpressionIndentation
{
    public static function atDepth(string $expression, int $depth): string
    {
        if ($depth === 0 || !str_contains($expression, "\n")) {
            return $expression;
        }

        $indent = str_repeat(' ', $depth * 4);
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
