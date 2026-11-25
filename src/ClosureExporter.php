<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ClosurePortabilityAnalyzer;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use ReflectionFunction;
use Componenta\VarExport\Internal\ClosureSourceResolver;
use Componenta\VarExport\Internal\ClosureExpressionBuilder;

final class ClosureExporter implements VarExportInterface, VarExportAwareInterface
{
    private ?VarExport $varExport = null;
    private readonly ClosureSourceResolver $sourceResolver;
    private readonly ClosureExpressionBuilder $expressionBuilder;

    public function __construct()
    {
        $this->sourceResolver = new ClosureSourceResolver();
        $this->expressionBuilder = new ClosureExpressionBuilder();
    }

    public function withVarExport(VarExport $varExport): static
    {
        $exporter = clone $this;
        $exporter->varExport = $varExport;

        return $exporter;
    }

    public function export(mixed $var): ?string
    {
        if (!$var instanceof Closure) {
            return null;
        }

        $varExport = $this->varExport;
        if ($varExport === null) {
            throw ExportException::exporterIsNotBound(self::class);
        }

        $reflection = new ReflectionFunction($var);
        $this->validateReflection($reflection);
        [$node, $sourceUsesStrictTypes] = $this->sourceResolver->resolve($reflection);

        new NodeTraverser(new ClosurePortabilityAnalyzer(
            $reflection->getFileName() ?: 'unknown',
            $reflection->getStartLine() ?: 1,
        ))->traverse([$node]);
        $this->assertNoByReferenceCaptures($node, $reflection);

        return $this->expressionBuilder->build($node, $reflection, $sourceUsesStrictTypes, $varExport);
    }

    private function validateReflection(ReflectionFunction $reflection): void
    {
        $name = $reflection->getName();
        if ($name !== '{closure}' && !str_starts_with($name, '{closure:')) {
            throw new ExportException(
                sprintf('Closure created from named callable "%s" cannot be exported.', $name),
                ['callable' => $name],
            );
        }

        $filename = $reflection->getFileName();
        if ($filename === false || !is_file($filename) || !is_readable($filename)) {
            throw new ExportException(
                sprintf('Cannot read closure source file "%s".', $filename ?: 'unknown'),
                ['filename' => $filename ?: null],
            );
        }

        if ($reflection->getClosureThis() !== null) {
            throw new ExportException(
                'Closure bound to $this cannot be exported safely.',
                ['closure' => $name],
                $filename,
                $reflection->getStartLine() ?: null,
            );
        }

        $staticLocals = array_diff_key(
            $reflection->getStaticVariables(),
            $reflection->getClosureUsedVariables(),
        );
        if ($staticLocals !== []) {
            throw new ExportException(
                sprintf(
                    'Closure static local variables cannot be exported: %s.',
                    implode(', ', array_map(
                        static fn (string $variable): string => '$' . $variable,
                        array_keys($staticLocals),
                    )),
                ),
                ['variables' => array_keys($staticLocals)],
                $filename,
                $reflection->getStartLine() ?: null,
            );
        }

        $scope = $reflection->getClosureScopeClass();
        if ($scope === null) {
            return;
        }

        $called = $reflection->getClosureCalledClass() ?? $scope;
        if ($scope->isAnonymous() || $called->getName() !== $scope->getName()) {
            throw new ExportException(
                'Closure class scope cannot be reproduced safely.',
                ['scope' => $scope->getName(), 'called_class' => $called->getName()],
                $filename,
                $reflection->getStartLine() ?: null,
            );
        }
    }

    private function assertNoByReferenceCaptures(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): void {
        $capture = (new NodeFinder())->findFirst(
            $node,
            static fn (Node $candidate): bool => $candidate instanceof ClosureUse && $candidate->byRef,
        );
        if ($capture !== null) {
            throw ExportException::nonPortableClosure(
                'captures by reference cannot be frozen',
                $reflection->getFileName() ?: null,
                $capture->getStartLine() ?: $reflection->getStartLine() ?: null,
            );
        }
    }
}
