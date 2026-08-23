<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class MagicConstantResolver extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $filename,
        private readonly string $namespace,
        private readonly string $functionName,
        private readonly string $className = '',
        private readonly string $traitName = '',
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof MagicConst) {
            return null;
        }

        return match (true) {
            $node instanceof MagicConst\File => new String_($this->filename),
            $node instanceof MagicConst\Dir => new String_(dirname($this->filename)),
            $node instanceof MagicConst\Namespace_ => new String_($this->namespace),
            $node instanceof MagicConst\Line => new Node\Scalar\Int_($node->getStartLine()),
            $node instanceof MagicConst\Class_ => new String_($this->className),
            $node instanceof MagicConst\Trait_ => new String_($this->traitName),
            $node instanceof MagicConst\Method,
            $node instanceof MagicConst\Function_ => new String_($this->functionName),
            $node instanceof MagicConst\Property => new String_(''),
            default => null,
        };
    }
}
