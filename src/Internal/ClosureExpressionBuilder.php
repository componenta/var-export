<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Closure;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExport;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use ReflectionClass;
use ReflectionFunction;

/** @internal */
final class ClosureExpressionBuilder
{
    private const int MAX_CAPTURE_DEPTH = 64;

    private readonly UseVariableInliner $inliner;
    private readonly PrettyPrinter $printer;

    /** @var array<int, FuncCall> */
    private array $guardTemplates = [];

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
        $key = (int) $sourceUsesStrictTypes;
        $template = $this->guardTemplates[$key] ??= $this->createStrictTypesGuard($sourceUsesStrictTypes);
        $nodes = (new NodeTraverser(new CloningVisitor()))->traverse([$template]);
        $guard = $nodes[0] ?? null;
        if (!$guard instanceof FuncCall || !$guard->name instanceof ClosureNode) {
            throw new \LogicException('Unable to copy the closure strict_types guard.');
        }

        $directive = $guard->name->stmts[0] ?? null;
        if (!$directive instanceof Declare_ || $directive->stmts === null) {
            throw new \LogicException('Unable to isolate the closure compilation directives.');
        }

        $lastIndex = count($directive->stmts) - 1;
        $lastReturn = $directive->stmts[$lastIndex] ?? null;
        if (!$lastReturn instanceof Return_) {
            throw new \LogicException('Unable to build the closure strict_types guard.');
        }

        $lastReturn->expr = $node;

        return $guard;
    }

    private function createStrictTypesGuard(bool $sourceUsesStrictTypes): FuncCall
    {
        $sourceMode = $sourceUsesStrictTypes ? 'true' : 'false';
        $statements = (new ParserFactory())
            ->createForHostVersion()
            ->parse(sprintf(<<<'PHP'
<?php
return (static function () {
    declare(ticks=0) {
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
    }
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
        $scopeName = $scope?->getName();
        $binding = new StaticCall(
            new FullyQualified(Closure::class),
            new Identifier('bind'),
            [
                new Node\Arg($node),
                new Node\Arg(new ConstFetch(new Name('null'))),
                new Node\Arg($scopeName === null
                    ? new ConstFetch(new Name('null'))
                    : new ClassConstFetch(
                        new FullyQualified($scopeName),
                        new Identifier('class'),
                    )),
            ],
        );
        $failure = new Throw_(new New_(
            new FullyQualified(ExportException::class),
            [new Node\Arg(new String_(
                $scopeName === null
                    ? 'Cannot restore unscoped closure.'
                    : sprintf('Cannot restore closure class scope "%s".', $scopeName),
            ))],
        ));
        $binding = new Coalesce($binding, $failure);

        if ($scopeName === null) {
            return $binding;
        }

        // Check the target environment before binding, which otherwise emits a
        // warning and returns null for missing or internal class scopes.
        return new Ternary(
            $this->isBindableScope($scopeName),
            $binding,
            clone $failure,
        );
    }

    private function isBindableScope(string $scopeName): Node\Expr
    {
        $classExists = new FuncCall(new FullyQualified('class_exists'), [
            new Node\Arg(new String_($scopeName)),
        ]);
        $interfaceExists = new FuncCall(new FullyQualified('interface_exists'), [
            new Node\Arg(new String_($scopeName)),
            new Node\Arg(new ConstFetch(new Name('false'))),
        ]);
        $traitExists = new FuncCall(new FullyQualified('trait_exists'), [
            new Node\Arg(new String_($scopeName)),
            new Node\Arg(new ConstFetch(new Name('false'))),
        ]);

        $isInternal = new MethodCall(
            new New_(new FullyQualified(ReflectionClass::class), [new Node\Arg(new String_($scopeName))]),
            new Identifier('isInternal'),
        );

        return new BooleanAnd(
            new BooleanOr(new BooleanOr($classExists, $interfaceExists), $traitExists),
            new BooleanNot($isInternal),
        );
    }
}
