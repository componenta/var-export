<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Internal\ClosurePortabilityAnalyzer;
use Componenta\VarExport\Internal\ClosureValidator;
use Componenta\VarExport\Internal\MagicConstantResolver;
use Componenta\VarExport\Internal\SourceSymbolResolver;
use Componenta\VarExport\Internal\UseVariableInliner;
use Componenta\VarExport\Source\ClosureSourceCache;
use Componenta\VarExport\Source\ClosureSourceCandidate;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType as NodeIntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType as NodeUnionType;
use PhpParser\NodeTraverser;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionReference;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

final readonly class ClosureExporter implements ClosureExporterInterface
{
    private ClosureValidator $validator;
    private UseVariableInliner $inliner;
    private PrettyPrinter $printer;
    private ClosureSourceCacheInterface $sourceCache;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ClosureSourceCacheInterface $astCache = null,
    ) {
        $this->validator = new ClosureValidator();
        $this->inliner = new UseVariableInliner();
        $this->sourceCache = $astCache ?? new ClosureSourceCache();
        $this->printer = new PrettyPrinter([
            'indent' => $this->config->indent,
            'phpVersion' => PhpVersion::getHostVersion(),
        ]);
    }

    public function export(Closure $closure): string
    {
        return $this->exportWithDepth($closure, 0);
    }

    public function exportWithDepth(Closure $closure, int $depth): string
    {
        if ($depth < 0) {
            throw new ClosureExportException(
                sprintf('Depth must be non-negative; got %d.', $depth),
                ['depth' => $depth],
            );
        }

        if ($depth > $this->config->maxDepth) {
            throw ClosureExportException::nestingDepthExceeded(
                $this->config->maxDepth,
                $depth,
            );
        }

        $reflection = $this->validator->validate($closure);
        $this->assertNoStaticLocalState($reflection);

        try {
            $candidate = $this->selectCandidate($reflection);
            $node = $candidate->node;
            $sourceNode = $node;

            $this->assertPortableExpression($node, $reflection);
            $this->resolveMagicConstants($node, $candidate, $reflection);
            $this->resolveSourceSymbols($node);

            if ($this->config->closureUseMode === ClosureUseMode::Inline) {
                $this->assertNoByReferenceCapture($node, $reflection);
                $node = $this->inliner->inline(
                    $node,
                    $reflection->getClosureUsedVariables(),
                    $this->config->maxDepth,
                    captureDepth: $depth + 1,
                    filename: $reflection->getFileName() ?: null,
                    line: $reflection->getStartLine() ?: null,
                );
            }

            $node = $this->isolateUnboundClosure($node, $sourceNode, $reflection);
            $node = $this->restoreClassScope($node, $reflection);
            $code = $this->printer->prettyPrintExpr($node);

            return $this->config->isPretty()
                ? $this->formatPretty($code, $depth)
                : $this->formatCompact($code);
        } catch (ClosureExportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ClosureExportException::internalFailure($reflection, $e);
        }
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self($config, $this->sourceCache);
    }

    private function assertPortableExpression(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): void {
        $analyzer = new ClosurePortabilityAnalyzer(
            $this->config->closureExportPolicy,
            $this->config->sourcePathPolicy,
            $reflection->getFileName() ?: null,
            $reflection->getStartLine() ?: null,
            $reflection->getName(),
        );

        (new NodeTraverser($analyzer))->traverse([$node]);
    }

    private function selectCandidate(ReflectionFunction $reflection): ClosureSourceCandidate
    {
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();

        if ($filename === false || $startLine === false) {
            throw ClosureExportException::sourceNotFound($filename ?: 'unknown');
        }

        $candidates = $this->sourceCache->candidates($filename, $startLine);
        if ($candidates === []) {
            throw ClosureExportException::nodeNotFound($startLine, $filename);
        }

        $matches = [];
        $deferredError = null;

        foreach ($candidates as $candidate) {
            $candidateError = null;
            if ($this->matchesReflection($candidate->node, $reflection, $candidateError)) {
                $matches[] = $candidate;
                continue;
            }

            $deferredError ??= $candidateError;
        }

        if ($matches === []) {
            if ($deferredError !== null) {
                throw $deferredError;
            }

            throw ClosureExportException::staleSource($reflection);
        }

        if (count($matches) !== 1) {
            throw ClosureExportException::ambiguousLocation($startLine, count($matches), $filename);
        }

        return $matches[0];
    }

    private function matchesReflection(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
        ?ClosureExportException &$deferredError = null,
    ): bool {
        if ($node->getEndLine() !== $reflection->getEndLine()) {
            return false;
        }

        if ((bool) $node->static !== $reflection->isStatic()) {
            return false;
        }

        if ((bool) $node->byRef !== $reflection->returnsReference()) {
            return false;
        }

        $parameters = $reflection->getParameters();
        if (count($node->params) !== count($parameters)) {
            return false;
        }

        /** @var list<array{Node\Expr, ReflectionParameter}> $defaults */
        $defaults = [];

        foreach ($parameters as $index => $parameter) {
            $nodeParameter = $node->params[$index];
            if (!is_string($nodeParameter->var->name) || $nodeParameter->var->name !== $parameter->getName()) {
                return false;
            }

            if ($nodeParameter->byRef !== $parameter->isPassedByReference()) {
                return false;
            }

            if ($nodeParameter->variadic !== $parameter->isVariadic()) {
                return false;
            }

            if (!$this->typesMatch($nodeParameter->type, $parameter->getType())) {
                return false;
            }

            if (($nodeParameter->default !== null) !== $parameter->isDefaultValueAvailable()) {
                return false;
            }

            if ($nodeParameter->default !== null) {
                $defaults[] = [$nodeParameter->default, $parameter];
            }
        }

        if (!$this->typesMatch($node->returnType, $reflection->getReturnType())) {
            return false;
        }

        if ($node instanceof ClosureNode) {
            $usedVariables = $reflection->getClosureUsedVariables();
            $useNames = [];
            foreach ($node->uses as $use) {
                if (!is_string($use->var->name)) {
                    continue;
                }

                $name = $use->var->name;
                $useNames[] = $name;
                $isReference = ReflectionReference::fromArrayElement($usedVariables, $name) !== null;
                if ($use->byRef !== $isReference) {
                    return false;
                }
            }

            if ($useNames !== array_keys($usedVariables)) {
                return false;
            }
        }

        foreach ($defaults as [$nodeDefault, $parameter]) {
            try {
                if (!$this->defaultMatches($nodeDefault, $parameter)) {
                    return false;
                }
            } catch (ClosureExportException $e) {
                $deferredError = $e;

                return false;
            }
        }

        return true;
    }

    private function typesMatch(?Node $nodeType, ?ReflectionType $reflectionType): bool
    {
        return $this->nodeTypeDescriptor($nodeType) === $this->reflectionTypeDescriptor($reflectionType);
    }

    private function nodeTypeDescriptor(?Node $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof NullableType) {
            return self::compositeTypeDescriptor('union', [
                $this->nodeTypeDescriptor($type->type),
                'named:null',
            ]);
        }

        if ($type instanceof NodeUnionType) {
            return self::compositeTypeDescriptor(
                'union',
                array_map(fn(Node $member): string => $this->nodeTypeDescriptor($member), $type->types),
            );
        }

        if ($type instanceof NodeIntersectionType) {
            return self::compositeTypeDescriptor(
                'intersection',
                array_map(fn(Node $member): string => $this->nodeTypeDescriptor($member), $type->types),
            );
        }

        if ($type instanceof Identifier || $type instanceof Name) {
            return 'named:' . strtolower(ltrim($type->toString(), '\\'));
        }

        return 'node:' . $type->getType();
    }

    private function reflectionTypeDescriptor(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionNamedType) {
            $descriptor = 'named:' . strtolower(ltrim($type->getName(), '\\'));
            if ($type->allowsNull() && strtolower($type->getName()) !== 'null' && strtolower($type->getName()) !== 'mixed') {
                return self::compositeTypeDescriptor('union', [$descriptor, 'named:null']);
            }

            return $descriptor;
        }

        if ($type instanceof ReflectionUnionType) {
            return self::compositeTypeDescriptor(
                'union',
                array_map(fn(ReflectionType $member): string => $this->reflectionTypeDescriptor($member), $type->getTypes()),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return self::compositeTypeDescriptor(
                'intersection',
                array_map(fn(ReflectionType $member): string => $this->reflectionTypeDescriptor($member), $type->getTypes()),
            );
        }

        return 'reflection:' . (string) $type;
    }

    /** @param list<string> $members */
    private static function compositeTypeDescriptor(string $kind, array $members): string
    {
        sort($members, SORT_STRING);

        return $kind . ':(' . implode(',', $members) . ')';
    }

    private function defaultMatches(Node\Expr $nodeDefault, ReflectionParameter $parameter): bool
    {
        if ($parameter->isDefaultValueConstant()) {
            $expected = $parameter->getDefaultValueConstantName();
            if ($expected === null) {
                return false;
            }

            return in_array(
                self::normalizeConstantName($expected),
                array_map(self::normalizeConstantName(...), $this->defaultConstantNames($nodeDefault)),
                true,
            );
        }

        try {
            $actual = (new ConstExprEvaluator())->evaluateSilently($nodeDefault);
        } catch (ConstExprEvaluationException $e) {
            throw ClosureExportException::unverifiableParameterDefault($parameter, $e);
        }

        return self::sameValue($actual, $parameter->getDefaultValue());
    }

    /** @return list<string> */
    private function defaultConstantNames(Node\Expr $expression): array
    {
        if ($expression instanceof ConstFetch) {
            $names = [$expression->name->toString()];
            $namespaced = $expression->name->getAttribute('namespacedName');
            if ($namespaced instanceof Name) {
                $names[] = $namespaced->toString();
            }

            return array_values(array_unique($names));
        }

        if ($expression instanceof ClassConstFetch && $expression->class instanceof Name && $expression->name instanceof Identifier) {
            return [$expression->class->toString() . '::' . $expression->name->toString()];
        }

        return [$this->printer->prettyPrintExpr($expression)];
    }

    private static function normalizeConstantName(string $name): string
    {
        return ltrim($name, '\\');
    }

    private static function sameValue(mixed $left, mixed $right): bool
    {
        if (is_float($left) && is_float($right)) {
            if (is_nan($left) || is_nan($right)) {
                return is_nan($left) && is_nan($right);
            }

            return pack('d', $left) === pack('d', $right);
        }

        if (is_array($left) && is_array($right)) {
            if (array_keys($left) !== array_keys($right)) {
                return false;
            }

            foreach ($left as $key => $value) {
                if (!self::sameValue($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $left === $right;
    }

    private function resolveMagicConstants(
        ClosureNode|ArrowFunction $node,
        ClosureSourceCandidate $candidate,
        ReflectionFunction $reflection,
    ): void {
        $scope = $reflection->getClosureScopeClass();
        $className = $candidate->trait !== ''
            ? ($scope?->getName() ?? '')
            : $candidate->class;
        $traverser = new NodeTraverser(new MagicConstantResolver(
            $reflection->getFileName() ?: '',
            $candidate->namespace,
            $reflection->getName(),
            $className,
            $candidate->trait,
        ));
        $traverser->traverse([$node]);
    }

    private function assertNoStaticLocalState(ReflectionFunction $reflection): void
    {
        $staticLocals = array_diff_key(
            $reflection->getStaticVariables(),
            $reflection->getClosureUsedVariables(),
        );
        if ($staticLocals === []) {
            return;
        }

        $names = array_map(
            static fn(string $name): string => '$' . $name,
            array_keys($staticLocals),
        );

        throw new ClosureExportException(
            sprintf(
                'Closure static local variables cannot be exported because their live runtime state is not reconstructable from source: %s.',
                implode(', ', $names),
            ),
            ['variables' => array_keys($staticLocals)],
            $reflection->getFileName() ?: null,
            $reflection->getStartLine() ?: null,
        );
    }

    private function resolveSourceSymbols(ClosureNode|ArrowFunction $node): void
    {
        (new NodeTraverser(new SourceSymbolResolver()))->traverse([$node]);
    }

    private function assertNoByReferenceCapture(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): void {
        if (!$node instanceof ClosureNode) {
            return;
        }

        $invalid = [];
        foreach ($node->uses as $use) {
            if ($use->byRef && is_string($use->var->name)) {
                $invalid[$use->var->name] = 'captured by reference';
            }
        }

        if ($invalid !== []) {
            throw ClosureExportException::cannotInlineUseVariables(
                $invalid,
                $reflection->getFileName() ?: null,
                $reflection->getStartLine() ?: null,
            );
        }
    }

    private function isolateUnboundClosure(
        Node\Expr $node,
        ClosureNode|ArrowFunction $sourceNode,
        ReflectionFunction $reflection,
    ): Node\Expr {
        if ($reflection->isStatic()) {
            return $node;
        }

        $uses = [];
        if ($this->config->closureUseMode === ClosureUseMode::Preserve) {
            if ($sourceNode instanceof ClosureNode) {
                foreach ($sourceNode->uses as $use) {
                    if (!is_string($use->var->name)) {
                        continue;
                    }

                    $uses[] = new ClosureUse(
                        new Variable($use->var->name),
                        $use->byRef,
                    );
                }
            } else {
                foreach (array_keys($reflection->getClosureUsedVariables()) as $name) {
                    $uses[] = new ClosureUse(new Variable($name));
                }
            }
        }

        return new FuncCall(new ClosureNode([
            'static' => true,
            'uses' => $uses,
            'stmts' => [new Return_($node)],
        ]));
    }

    private function restoreClassScope(Node\Expr $node, ReflectionFunction $reflection): Node\Expr
    {
        $scope = $reflection->getClosureScopeClass();
        if ($scope === null) {
            return $node;
        }

        return new StaticCall(
            new FullyQualified(Closure::class),
            new Identifier('bind'),
            [
                new Node\Arg($node),
                new Node\Arg(new ConstFetch(new FullyQualified('null'))),
                new Node\Arg(new ClassConstFetch(new FullyQualified($scope->getName()), new Identifier('class'))),
            ],
        );
    }

    private function formatCompact(string $code): string
    {
        $tokens = \PhpToken::tokenize('<?php ' . $code);
        $result = '';
        $pendingSpace = false;

        foreach ($tokens as $index => $token) {
            if ($index === 0 && $token->is(T_OPEN_TAG)) {
                continue;
            }

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $pendingSpace = $result !== '';
                continue;
            }

            if ($pendingSpace) {
                $result .= ' ';
                $pendingSpace = false;
            }

            $result .= $token->text;
        }

        return trim($result);
    }

    private function formatPretty(string $code, int $depth): string
    {
        if ($depth === 0 || !str_contains($code, "\n")) {
            return $code;
        }

        $baseIndent = str_repeat($this->config->indent, $depth);
        $tokens = \PhpToken::tokenize('<?php ' . $code);
        $result = '';

        foreach ($tokens as $index => $token) {
            if ($index === 0 && $token->is(T_OPEN_TAG)) {
                continue;
            }

            if ($this->isStringLiteralToken($token)) {
                $result .= $token->text;
                continue;
            }

            $result .= str_replace("\n", "\n" . $baseIndent, $token->text);
        }

        return $result;
    }

    private function isStringLiteralToken(\PhpToken $token): bool
    {
        return $token->is([
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_START_HEREDOC,
            T_END_HEREDOC,
            T_INLINE_HTML,
        ]);
    }
}
