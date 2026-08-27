<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ClosureExporterInterface;
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
    private ClosureSourceCache $sourceCache;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?ClosureSourceCache $sourceCache = null,
    ) {
        $this->validator = new ClosureValidator();
        $this->inliner = new UseVariableInliner();
        $this->sourceCache = $sourceCache ?? new ClosureSourceCache();
        $this->printer = new PrettyPrinter([
            'indent' => $this->config->indent,
            'phpVersion' => PhpVersion::getHostVersion(),
        ]);
    }

    public function export(Closure $closure): string
    {
        return $this->exportWithContext($closure, ExportContext::root());
    }

    public function exportWithDepth(Closure $closure, int $depth): string
    {
        if ($depth < 0) {
            throw new ClosureExportException(
                sprintf('Depth must be non-negative; got %d.', $depth),
                ['depth' => $depth],
            );
        }

        return $this->exportWithContext(
            $closure,
            new ExportContext($depth, baseIndent: str_repeat($this->config->indent, $depth)),
        );
    }

    public function exportWithContext(Closure $closure, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw ClosureExportException::nestingDepthExceeded(
                $this->config->maxDepth,
                $context->depth,
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
                    self::closureUsedVariables($reflection),
                    $this->config->maxDepth,
                    captureDepth: $context->depth + 1,
                    filename: $reflection->getFileName() ?: null,
                    line: $reflection->getStartLine() ?: null,
                    sortKeys: $this->config->sortKeys,
                );
            }

            $node = $this->isolateUnboundClosure($node, $sourceNode, $reflection);
            $node = $this->restoreRuntimeScope($node, $reflection);
            $code = $this->printer->prettyPrintExpr($node);

            return $this->config->isPretty()
                ? $this->formatPretty($code, $context->baseIndent)
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
            if ($this->matchesReflection($candidate, $reflection, $candidateError)) {
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
        ClosureSourceCandidate $candidate,
        ReflectionFunction $reflection,
        ?ClosureExportException &$deferredError = null,
    ): bool {
        if (!$this->sourceOwnerMatches($candidate, $reflection)) {
            return false;
        }

        $node = $candidate->node;
        if ($node->getEndLine() !== $reflection->getEndLine()) {
            return false;
        }

        if ((bool) $node->static !== $reflection->isStatic()) {
            return false;
        }

        if ((bool) $node->byRef !== $reflection->returnsReference()) {
            return false;
        }

        if ($this->nodeIsGenerator($node) !== $reflection->isGenerator()) {
            return false;
        }

        if ($this->ownScopeContains($node, Node\Stmt\Static_::class)) {
            return false;
        }

        if (!$this->attributesMatch($node->attrGroups, $reflection->getAttributes(), $reflection, $deferredError)) {
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
            if (
                !$nodeParameter->var instanceof Variable
                || !is_string($nodeParameter->var->name)
                || $nodeParameter->var->name !== $parameter->getName()
            ) {
                return false;
            }

            if ($nodeParameter->byRef !== $parameter->isPassedByReference()) {
                return false;
            }

            if ($nodeParameter->variadic !== $parameter->isVariadic()) {
                return false;
            }

            if (!$this->attributesMatch($nodeParameter->attrGroups, $parameter->getAttributes(), $reflection, $deferredError)) {
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
            $usedVariables = self::closureUsedVariables($reflection);
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
        } elseif ($this->arrowUsedVariableNames($node) !== array_keys(self::closureUsedVariables($reflection))) {
            return false;
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

    private function sourceOwnerMatches(ClosureSourceCandidate $candidate, ReflectionFunction $reflection): bool
    {
        [$closureDepth, $name] = self::closureNameParts($reflection->getName());
        if ($candidate->closureDepth !== $closureDepth) {
            return false;
        }

        if ($candidate->function !== '') {
            return str_starts_with($name, $candidate->function . '():');
        }

        if ($candidate->trait !== '') {
            if ($candidate->method !== '') {
                return str_starts_with($name, $candidate->trait . '::' . $candidate->method . '():');
            }

            return str_starts_with($name, $candidate->trait . '::');
        }

        if ($candidate->method !== '') {
            if ($candidate->class !== '') {
                return str_starts_with($name, $candidate->class . '::' . $candidate->method . '():');
            }

            return false;
        }

        if ($candidate->class !== '') {
            return str_starts_with($name, $candidate->class . '::');
        }

        return str_starts_with($name, $reflection->getFileName() . ':');
    }

    /** @return array{int, string} */
    private static function closureNameParts(string $name): array
    {
        $depth = 0;
        $prefix = '{closure:';
        while (str_starts_with($name, $prefix)) {
            ++$depth;
            $name = substr($name, strlen($prefix));
        }

        return [$depth, $name];
    }

    /**
     * @param array<array-key, Node\AttributeGroup> $nodeGroups
     * @param list<\ReflectionAttribute<object>> $reflectionAttributes
     */
    private function attributesMatch(
        array $nodeGroups,
        array $reflectionAttributes,
        ReflectionFunction $reflection,
        ?ClosureExportException &$deferredError,
    ): bool {
        $nodeAttributes = [];
        foreach ($nodeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $nodeAttributes[] = $attribute;
            }
        }

        if (count($nodeAttributes) !== count($reflectionAttributes)) {
            return false;
        }

        foreach ($nodeAttributes as $index => $nodeAttribute) {
            $reflectionAttribute = $reflectionAttributes[$index];
            if (
                ltrim($nodeAttribute->name->toString(), '\\')
                !== ltrim($reflectionAttribute->getName(), '\\')
            ) {
                return false;
            }

            $reflectionArgumentSyntax = self::reflectionAttributeArgumentSyntax($reflectionAttribute);
            if (count($nodeAttribute->args) !== count($reflectionArgumentSyntax)) {
                return false;
            }

            if ($nodeAttribute->args === []) {
                continue;
            }

            if (self::reflectionArgumentsMayExecute($reflectionArgumentSyntax)) {
                $deferredError = self::unverifiableAttributeArguments($reflectionAttribute->getName(), $reflection);

                return false;
            }

            $nodeArguments = [];
            try {
                foreach ($nodeAttribute->args as $argumentIndex => $argument) {
                    $key = $argument->name?->toString() ?? $argumentIndex;
                    $nodeArguments[$key] = (new ConstExprEvaluator())->evaluateSilently($argument->value);
                }
            } catch (ConstExprEvaluationException $e) {
                $deferredError = self::unverifiableAttributeArguments(
                    $reflectionAttribute->getName(),
                    $reflection,
                    $e,
                );

                return false;
            }

            if (!self::sameValue($nodeArguments, $reflectionAttribute->getArguments())) {
                return false;
            }
        }

        return true;
    }

    private static function unverifiableAttributeArguments(
        string $attribute,
        ReflectionFunction $reflection,
        ?Throwable $previous = null,
    ): ClosureExportException {
        return new ClosureExportException(
            sprintf(
                'Cannot verify closure or parameter attribute arguments for "%s" without executing or autoloading user code.',
                $attribute,
            ),
            ['attribute' => $attribute, 'closure' => $reflection->getName()],
            $reflection->getFileName() ?: null,
            $reflection->getStartLine() ?: null,
            $previous,
        );
    }

    /**
     * @param \ReflectionAttribute<object> $attribute
     * @return list<string>
     */
    private static function reflectionAttributeArgumentSyntax(\ReflectionAttribute $attribute): array
    {
        $matches = [];
        if (preg_match_all('/^\s*Argument #\d+ \[ (.*) \]$/m', (string) $attribute, $matches) === false) {
            return [];
        }

        /** @var list<string> $arguments */
        $arguments = $matches[1];

        return $arguments;
    }

    /** @param list<string> $arguments */
    private static function reflectionArgumentsMayExecute(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (self::reflectionExpressionMayExecute($argument)) {
                return true;
            }
        }

        return false;
    }

    private static function reflectionExpressionMayExecute(string $expression): bool
    {
        foreach (\PhpToken::tokenize('<?php ' . $expression) as $token) {
            if ($token->is([T_NEW, T_DOUBLE_COLON])) {
                return true;
            }
        }

        return false;
    }

    private function nodeIsGenerator(ClosureNode|ArrowFunction $node): bool
    {
        return $this->ownScopeContains($node, Node\Expr\Yield_::class)
            || $this->ownScopeContains($node, Node\Expr\YieldFrom::class);
    }

    /** @param class-string<Node> $nodeClass */
    private function ownScopeContains(Node $node, string $nodeClass): bool
    {
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};
            if ($value instanceof Node) {
                if ($this->ownScopeChildContains($value, $nodeClass)) {
                    return true;
                }

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $child) {
                if ($child instanceof Node && $this->ownScopeChildContains($child, $nodeClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param class-string<Node> $nodeClass */
    private function ownScopeChildContains(Node $node, string $nodeClass): bool
    {
        if ($node instanceof $nodeClass) {
            return true;
        }

        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }

        return $this->ownScopeContains($node, $nodeClass);
    }

    /** @return list<string> */
    private function arrowUsedVariableNames(ArrowFunction $node): array
    {
        /** @var array<string, true> $names */
        $names = [];
        $this->collectArrowUsedVariableNames($node->expr, $names);

        foreach ($node->params as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                unset($names[$parameter->var->name]);
            }
        }

        foreach ([
            'this',
            'GLOBALS',
            '_SERVER',
            '_GET',
            '_POST',
            '_FILES',
            '_COOKIE',
            '_SESSION',
            '_REQUEST',
            '_ENV',
        ] as $name) {
            unset($names[$name]);
        }

        return array_keys($names);
    }

    /** @param array<string, true> $names */
    private function collectArrowUsedVariableNames(Node $node, array &$names): void
    {
        if ($node instanceof Variable) {
            if (is_string($node->name)) {
                $names[$node->name] = true;
            } elseif ($node->name instanceof Node) {
                $this->collectArrowUsedVariableNames($node->name, $names);
            }

            return;
        }

        if ($node instanceof ClosureNode) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $names[$use->var->name] = true;
                }
            }

            return;
        }

        if ($node instanceof ArrowFunction) {
            foreach ($this->arrowUsedVariableNames($node) as $name) {
                $names[$name] = true;
            }

            return;
        }

        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};
            if ($value instanceof Node) {
                $this->collectArrowUsedVariableNames($value, $names);
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $child) {
                if ($child instanceof Node) {
                    $this->collectArrowUsedVariableNames($child, $names);
                }
            }
        }
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
            if (
                $type->allowsNull()
                && strtolower($type->getName()) !== 'null'
                && strtolower($type->getName()) !== 'mixed'
            ) {
                return self::compositeTypeDescriptor('union', [$descriptor, 'named:null']);
            }

            return $descriptor;
        }

        if ($type instanceof ReflectionUnionType) {
            return self::compositeTypeDescriptor(
                'union',
                array_map(
                    fn(ReflectionType $member): string => $this->reflectionTypeDescriptor($member),
                    $type->getTypes(),
                ),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return self::compositeTypeDescriptor(
                'intersection',
                array_map(
                    fn(ReflectionType $member): string => $this->reflectionTypeDescriptor($member),
                    $type->getTypes(),
                ),
            );
        }

        return 'reflection:' . (string) $type;
    }

    /** @param array<string> $members */
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

        $reflectionDefault = self::reflectionParameterDefaultSyntax($parameter);
        if ($reflectionDefault === null || self::reflectionExpressionMayExecute($reflectionDefault)) {
            throw ClosureExportException::unverifiableParameterDefault(
                $parameter,
                new \RuntimeException('Reading the runtime default value may execute or autoload user code.'),
            );
        }

        try {
            $actual = (new ConstExprEvaluator())->evaluateSilently($nodeDefault);
        } catch (ConstExprEvaluationException $e) {
            throw ClosureExportException::unverifiableParameterDefault($parameter, $e);
        }

        return self::sameValue($actual, $parameter->getDefaultValue());
    }

    private static function reflectionParameterDefaultSyntax(ReflectionParameter $parameter): ?string
    {
        $signature = (string) $parameter;
        $marker = ' = ';
        $start = strpos($signature, $marker);
        $end = strrpos($signature, ' ]');
        if ($start === false || $end === false) {
            return null;
        }

        $start += strlen($marker);
        if ($end < $start) {
            return null;
        }

        return substr($signature, $start, $end - $start);
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

        if (
            $expression instanceof ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier
        ) {
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

    /** @return array<string, mixed> */
    private static function closureUsedVariables(ReflectionFunction $reflection): array
    {
        /** @var array<string, mixed> $variables */
        $variables = $reflection->getClosureUsedVariables();

        return $variables;
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
            self::closureUsedVariables($reflection),
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

        if (
            $this->config->closureUseMode === ClosureUseMode::Inline
            && self::closureUsedVariables($reflection) !== []
        ) {
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
                foreach (array_keys(self::closureUsedVariables($reflection)) as $name) {
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

    private function restoreRuntimeScope(Node\Expr $node, ReflectionFunction $reflection): Node\Expr
    {
        $scope = $reflection->getClosureScopeClass();
        $scopeArgument = $scope === null
            ? new ConstFetch(new Name('null'))
            : new ClassConstFetch(
                new FullyQualified($scope->getName()),
                new Identifier('class'),
            );

        return new StaticCall(
            new FullyQualified(Closure::class),
            new Identifier('bind'),
            [
                new Node\Arg($node),
                new Node\Arg(new ConstFetch(new Name('null'))),
                new Node\Arg($scopeArgument),
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

    private function formatPretty(string $code, string $baseIndent): string
    {
        if ($baseIndent === '' || !str_contains($code, "\n")) {
            return $code;
        }

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
