<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class DetachedNodeCloner extends NodeVisitorAbstract
{
    public function enterNode(Node $node): Node
    {
        $clone = clone $node;
        $attributes = $clone->getAttributes();

        foreach ($attributes as $name => $value) {
            if ($value instanceof Node) {
                $attributes[$name] = clone $value;
            } elseif (is_array($value)) {
                $attributes[$name] = self::cloneAttributeArray($value);
            }
        }

        $clone->setAttributes($attributes);

        return $clone;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function cloneAttributeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Node) {
                $values[$key] = clone $value;
            } elseif (is_array($value)) {
                $values[$key] = self::cloneAttributeArray($value);
            }
        }

        return $values;
    }
}
