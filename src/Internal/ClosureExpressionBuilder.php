<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Closure;
use Componenta\VarExport\Exception\ExportException;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use ReflectionFunction;
use Componenta\VarExport\VarExport;

/** @internal */
final class ClosureExpressionBuilder
{
    private const int MAX_CAPTURE_DEPTH = 64;

    private readonly UseVariableInliner $inliner;
    private readonly PrettyPrinter $printer;

    public function __construct()
    {
        $this->inliner = new UseVariableInliner();
        $this->printer = new PrettyPrinter([
            'indent' => '    ',
            'phpVersion' => PhpVersion::getHostVersion(),
        ]);
    }

    public function build(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
        bool $sourceUsesStrictTypes,
        VarExport $varExport,
    ): string {
        $node = $this->inliner->inline(
            $node,
            self::usedVariables($reflection),
            self::MAX_CAPTURE_DEPTH,
            $varExport,
            filename: $reflection->getFileName() ?: null,
            line: $reflection->getStartLine() ?: null,
        );
        $node = $this->isolateUnboundClosure($node, $reflection);
        $node = $this->restoreRuntimeScope($node, $reflection);
        $node = $this->guardStrictTypes($node, $sourceUsesStrictTypes);

        return $this->printer->prettyPrintExpr($node);
    }

    private function guardStrictTypes(Node\Expr $node, bool $sourceUsesStrictTypes): Node\Expr
    {
        $sourceMode = $sourceUsesStrictTypes ? 'true' : 'false';
        $statements = (new ParserFactory())
            ->createForHostVersion()
            ->parse(sprintf(<<<'PHP'
<?php
return (static function () {
    if ((static function () {
        try {
            (static fn (int $value): int => $value)('1');
        } catch (\TypeError) {
            return true;
        }
        return false;
    })() !== %s) {
        throw new \Componenta\VarExport\Exception\ExportException(
            'Closure strict_types mode does not match the generated PHP file.',
            [
                'source_strict_types' => %s,
                'target_strict_types' => !%s,
            ],
        );
    }

    return null;
})();
PHP, $sourceMode, $sourceMode, $sourceMode));
        $return = $statements[0] ?? null;

        if (
            !$return instanceof Return_
            || !$return->expr instanceof FuncCall
            || !$return->expr->name instanceof ClosureNode
        ) {
            throw new \LogicException('Unable to build the closure strict_types guard.');
        }

        $factory = $return->expr->name;
        $lastIndex = count($factory->stmts) - 1;
        $lastReturn = $factory->stmts[$lastIndex] ?? null;
        if (!$lastReturn instanceof Return_) {
            throw new \LogicException('Unable to build the closure strict_types guard.');
        }

        $lastReturn->expr = $node;

        return $return->expr;
    }

    /** @return array<string, mixed> */
    private static function usedVariables(ReflectionFunction $reflection): array
    {
        $variables = [];
        foreach ($reflection->getClosureUsedVariables() as $name => $value) {
            if (!is_string($name)) {
                throw new \LogicException('Closure capture names must be strings.');
            }

            $variables[$name] = $value;
        }

        return $variables;
    }

    private function isolateUnboundClosure(
        Node\Expr $node,
        ReflectionFunction $reflection,
    ): Node\Expr {
        if ($reflection->isStatic() || $reflection->getClosureUsedVariables() !== []) {
            return $node;
        }

        return new FuncCall(new ClosureNode([
            'static' => true,
            'stmts' => [new Return_($node)],
        ]));
    }

    private function restoreRuntimeScope(
        Node\Expr $node,
        ReflectionFunction $reflection,
    ): Node\Expr {
        $scope = $reflection->getClosureScopeClass();
        return new StaticCall(
            new FullyQualified(Closure::class),
            new Identifier('bind'),
            [
                new Node\Arg($node),
                new Node\Arg(new ConstFetch(new Name('null'))),
                new Node\Arg($scope === null
                    ? new ConstFetch(new Name('null'))
                    : new ClassConstFetch(
                        new FullyQualified($scope->getName()),
                        new Identifier('class'),
                    )),
            ],
        );
    }
}
