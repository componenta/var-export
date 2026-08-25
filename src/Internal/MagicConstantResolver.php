<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class MagicConstantResolver extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $functionStack = [];

    private bool $rootClosureSeen = false;
    private string $currentFunctionName;

    public function __construct(
        private readonly string $filename,
        private readonly string $namespace,
        string $functionName,
        private readonly string $className = '',
        private readonly string $traitName = '',
    ) {
        $this->currentFunctionName = $functionName;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            if (!$this->rootClosureSeen) {
                $this->rootClosureSeen = true;

                return null;
            }

            $this->functionStack[] = $this->currentFunctionName;
            $this->currentFunctionName = sprintf(
                '{closure:%s:%d}',
                $this->currentFunctionName,
                $node->getStartLine(),
            );

            return null;
        }

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
            $node instanceof MagicConst\Function_ => new String_($this->currentFunctionName),
            // __PROPERTY__ belongs to the property-hook body itself. A nested
            // closure has no property context, so PHP evaluates it as ''.
            $node instanceof MagicConst\Property => new String_(''),
            default => null,
        };
    }

    public function leaveNode(Node $node): null
    {
        if (($node instanceof Closure || $node instanceof ArrowFunction) && $this->functionStack !== []) {
            $this->currentFunctionName = array_pop($this->functionStack);
        }

        return null;
    }
}
