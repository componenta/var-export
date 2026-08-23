<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\SourcePathPolicy;
use Componenta\VarExport\Exception\ClosureExportException;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\NodeVisitorAbstract;

final class ClosurePortabilityAnalyzer extends NodeVisitorAbstract
{
    public function __construct(
        private readonly ClosureExportPolicy $policy,
        private readonly SourcePathPolicy $sourcePathPolicy,
        private readonly ?string $filename = null,
        private readonly ?int $line = null,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if (
            ($this->policy === ClosureExportPolicy::PortableExpression
                || $this->sourcePathPolicy === SourcePathPolicy::Reject)
            && ($node instanceof MagicConst\File || $node instanceof MagicConst\Dir)
        ) {
            throw ClosureExportException::nonPortableExpression(
                sprintf('%s is source-root dependent', $node instanceof MagicConst\File ? '__FILE__' : '__DIR__'),
                $this->filename,
                $node->getStartLine() ?: $this->line,
            );
        }

        if ($this->policy !== ClosureExportPolicy::PortableExpression) {
            return null;
        }

        if ($node instanceof Include_) {
            throw ClosureExportException::nonPortableExpression(
                'include/require depends on artifact location and include_path',
                $this->filename,
                $node->getStartLine() ?: $this->line,
            );
        }

        if ($node instanceof Eval_) {
            throw ClosureExportException::nonPortableExpression(
                'eval() inherits runtime context that cannot be represented safely as a standalone portable expression',
                $this->filename,
                $node->getStartLine() ?: $this->line,
            );
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $this->assertPortableName($node->name, 'function', $node->getStartLine());

            return null;
        }

        if ($node instanceof ConstFetch) {
            $name = strtolower($node->name->toString());
            if (in_array($name, ['true', 'false', 'null'], true)) {
                return null;
            }

            $this->assertPortableName($node->name, 'constant', $node->getStartLine());
        }

        return null;
    }

    private function assertPortableName(Name $name, string $kind, int $line): void
    {
        if ($name instanceof FullyQualified || $name instanceof Name\Relative) {
            return;
        }

        $namespaced = $name->getAttribute('namespacedName');
        if (!$name->isUnqualified() || !$namespaced instanceof Name) {
            return;
        }

        throw ClosureExportException::nonPortableExpression(
            sprintf(
                'unqualified %s "%s" inside namespace "%s" has PHP namespace fallback semantics; '
                . 'use an imported or fully-qualified name',
                $kind,
                $name->toString(),
                self::namespaceOf($namespaced),
            ),
            $this->filename,
            $line ?: $this->line,
        );
    }

    private static function namespaceOf(Name $namespaced): string
    {
        $parts = $namespaced->getParts();
        array_pop($parts);

        return implode('\\', $parts);
    }
}
