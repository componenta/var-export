<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ClosurePortabilityAnalyzer;
use Componenta\VarExport\Internal\UseVariableInliner;
use Componenta\VarExport\Internal\WarningGuard;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use ReflectionFunction;
use ReflectionAttribute;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionReference;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

final class ClosureExporter implements VarExportInterface, VarExportAwareInterface
{
    private const int MAX_CAPTURE_DEPTH = 64;

    private ?VarExport $varExport = null;
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
        [$node, $sourceUsesStrictTypes] = $this->findNode($reflection);

        new NodeTraverser(new ClosurePortabilityAnalyzer(
            $reflection->getFileName() ?: 'unknown',
            $reflection->getStartLine() ?: 1,
        ))->traverse([$node]);
        $this->assertNoByReferenceCaptures($node, $reflection);

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

    /** @return array{ClosureNode|ArrowFunction, bool} */
    private function findNode(ReflectionFunction $reflection): array
    {
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if ($filename === false || $startLine === false || $endLine === false) {
            throw new ExportException('Closure source location is unavailable.');
        }

        $source = WarningGuard::run(static fn (): string|false => file_get_contents($filename));
        if ($source === false) {
            throw new ExportException(
                sprintf('Cannot read closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $startLine,
            );
        }

        try {
            $statements = (new ParserFactory())
                ->createForHostVersion()
                ->parse($source);
        } catch (Throwable $error) {
            throw new ExportException(
                sprintf('Cannot parse closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $startLine,
                $error,
            );
        }

        if ($statements === null) {
            throw new ExportException(
                sprintf('Cannot parse closure source file "%s".', $filename),
                ['filename' => $filename],
                $filename,
                $startLine,
            );
        }

        $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
        $statements = (new NodeTraverser(new ParentConnectingVisitor()))->traverse($statements);
        $nodes = (new NodeFinder())->find(
            $statements,
            fn (Node $node): bool => ($node instanceof ClosureNode || $node instanceof ArrowFunction)
                && $node->getStartLine() <= $reflection->getStartLine()
                && $node->getEndLine() === $reflection->getEndLine(),
        );
        $candidates = [];
        foreach ($nodes as $candidate) {
            if ($candidate instanceof ClosureNode || $candidate instanceof ArrowFunction) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            throw new ExportException(
                sprintf(
                    'Expected one closure expression at %s:%d, found %d.',
                    $filename,
                    $startLine,
                    count($candidates),
                ),
                ['filename' => $filename, 'line' => $startLine, 'candidates' => count($candidates)],
                $filename,
                $startLine,
            );
        }

        $matches = [];
        $deferredError = null;
        foreach ($candidates as $candidate) {
            try {
                if ($this->matchesReflection($candidate, $reflection)) {
                    $matches[] = $candidate;
                }
            } catch (ExportException $error) {
                $deferredError ??= $error;
            }
        }

        if ($matches === [] && $deferredError !== null) {
            throw $deferredError;
        }

        if ($matches === []) {
            throw new ExportException(
                'Closure source no longer matches the runtime closure metadata.',
                ['filename' => $filename, 'line' => $startLine],
                $filename,
                $startLine,
            );
        }

        if (count($matches) !== 1) {
            throw new ExportException(
                sprintf(
                    'Expected one closure expression at %s:%d, found %d.',
                    $filename,
                    $startLine,
                    count($matches),
                ),
                ['filename' => $filename, 'line' => $startLine, 'candidates' => count($matches)],
                $filename,
                $startLine,
            );
        }

        return [
            $matches[0],
            self::sourceUsesStrictTypes($statements, $matches[0], $reflection),
        ];
    }

    /** @param array<array-key, Node> $statements */
    private static function sourceUsesStrictTypes(
        array $statements,
        ClosureNode|ArrowFunction $closure,
        ReflectionFunction $reflection,
    ): bool {
        $strictTypes = false;
        $declarations = (new NodeFinder())->findInstanceOf($statements, Declare_::class);

        foreach ($declarations as $declaration) {
            foreach ($declaration->declares as $declare) {
                $name = strtolower($declare->key->toString());
                if ($name === 'strict_types') {
                    $strictTypes = $declare->value instanceof Int_
                        && $declare->value->value === 1;

                    continue;
                }

                if (
                    self::nodeHasAncestor($declaration, $closure)
                    || !self::declarationAffectsNode($declaration, $closure)
                ) {
                    continue;
                }

                throw ExportException::nonPortableClosure(
                    sprintf('declare(%s) cannot be preserved outside the closure expression', $name),
                    $reflection->getFileName() ?: null,
                    $declaration->getStartLine() ?: $reflection->getStartLine() ?: null,
                );
            }
        }

        return $strictTypes;
    }

    private static function declarationAffectsNode(Declare_ $declaration, Node $node): bool
    {
        if ($declaration->stmts !== null) {
            return self::nodeHasAncestor($node, $declaration);
        }

        $parent = $declaration->getAttribute('parent');
        if ($parent instanceof Node && !self::nodeHasAncestor($node, $parent)) {
            return false;
        }

        $declarationPosition = $declaration->getStartFilePos();
        $nodePosition = $node->getStartFilePos();

        return $declarationPosition < 0
            || $nodePosition < 0
            || $declarationPosition <= $nodePosition;
    }

    private static function nodeHasAncestor(Node $node, Node $ancestor): bool
    {
        $current = $node;

        while (true) {
            if ($current === $ancestor) {
                return true;
            }

            $parent = $current->getAttribute('parent');
            if (!$parent instanceof Node) {
                return false;
            }

            $current = $parent;
        }
    }

    private function guardStrictTypes(Node\Expr $node, bool $sourceUsesStrictTypes): Node\Expr
    {
        $sourceMode = $sourceUsesStrictTypes ? 'true' : 'false';
        $statements = (new ParserFactory())
            ->createForHostVersion()
            ->parse(sprintf(<<<'PHP'
<?php
return (static function () {
    $componentaVarExportStrictTypes = false;

    try {
        (static fn (int $value): int => $value)('1');
    } catch (\TypeError) {
        $componentaVarExportStrictTypes = true;
    }

    if ($componentaVarExportStrictTypes !== %s) {
        throw new \Componenta\VarExport\Exception\ExportException(
            'Closure strict_types mode does not match the generated PHP file.',
            [
                'source_strict_types' => %s,
                'target_strict_types' => $componentaVarExportStrictTypes,
            ],
        );
    }

    return null;
})();
PHP, $sourceMode, $sourceMode));
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

    private function matchesSignature(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): bool {
        if (
            (bool) $node->static !== $reflection->isStatic()
            || (bool) $node->byRef !== $reflection->returnsReference()
            || count($node->params) !== $reflection->getNumberOfParameters()
            || $this->nodeType($node->returnType, $reflection)
                !== $this->reflectionType($reflection->getReturnType(), $reflection)
        ) {
            return false;
        }

        foreach ($reflection->getParameters() as $index => $parameter) {
            $variable = $node->params[$index]->var;
            if (
                !$variable instanceof Variable
                || !is_string($variable->name)
                || $variable->name !== $parameter->getName()
                || $node->params[$index]->byRef !== $parameter->isPassedByReference()
                || $node->params[$index]->variadic !== $parameter->isVariadic()
                || $this->nodeType($node->params[$index]->type, $reflection)
                    !== $this->reflectionType($parameter->getType(), $reflection)
                || !$this->defaultMatches($node->params[$index]->default, $parameter, $reflection)
            ) {
                return false;
            }
        }

        return true;
    }

    private function matchesReflection(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): bool {
        if (
            !$this->sourceOwnerMatches($node, $reflection)
            || !$this->matchesSignature($node, $reflection)
            || $this->nodeIsGenerator($node) !== $reflection->isGenerator()
            || $this->ownScopeContains($node, Static_::class)
            || !$this->attributesMatch($node->attrGroups, $reflection->getAttributes(), $reflection)
            || !$this->captureMetadataMatches($node, $reflection)
        ) {
            return false;
        }

        foreach ($reflection->getParameters() as $index => $parameter) {
            if (!$this->attributesMatch(
                $node->params[$index]->attrGroups,
                $parameter->getAttributes(),
                $reflection,
            )) {
                return false;
            }
        }

        return true;
    }

    private function sourceOwnerMatches(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): bool {
        [$runtimeDepth, $runtimeName] = self::closureNameParts($reflection->getName());
        if ($this->closureDepth($node) !== $runtimeDepth) {
            return false;
        }

        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof Function_) {
                $function = $parent->namespacedName?->toString() ?? $parent->name->toString();

                return str_starts_with($runtimeName, $function . '():');
            }

            if ($parent instanceof ClassMethod || $parent instanceof PropertyHook) {
                if ($parent instanceof ClassMethod) {
                    $method = $parent->name->toString();
                } else {
                    $property = $parent->getAttribute('parent');
                    if (!$property instanceof Property) {
                        return false;
                    }

                    $method = '$' . $property->props[0]->name->toString()
                        . '::' . $parent->name->toString();
                }
                $class = $parent->getAttribute('parent');
                while ($class instanceof Node && !$class instanceof ClassLike) {
                    $class = $class->getAttribute('parent');
                }

                if (!$class instanceof ClassLike || $class->name === null) {
                    return false;
                }

                $owner = $class->namespacedName?->toString() ?? $class->name->toString();

                return str_starts_with($runtimeName, $owner . '::' . $method . '():');
            }

            $parent = $parent->getAttribute('parent');
        }

        $filename = $reflection->getFileName();

        return $filename !== false && str_starts_with($runtimeName, $filename . ':');
    }

    private function closureDepth(ClosureNode|ArrowFunction $node): int
    {
        $depth = 0;
        $current = $node;

        while (true) {
            if ($current instanceof ClosureNode || $current instanceof ArrowFunction) {
                ++$depth;
            }

            $parent = $current->getAttribute('parent');
            if (!$parent instanceof Node || $parent instanceof Function_ || $parent instanceof ClassMethod) {
                return $depth;
            }

            $current = $parent;
        }
    }

    /** @return array{int, string} */
    private static function closureNameParts(string $name): array
    {
        $depth = 0;
        while (str_starts_with($name, '{closure:')) {
            ++$depth;
            $name = substr($name, strlen('{closure:'));
        }

        return [$depth, $name];
    }

    private function nodeType(
        null|Identifier|Name|ComplexType $type,
        ReflectionFunction $reflection,
    ): ?string {
        if ($type === null) {
            return null;
        }

        if ($type instanceof NullableType) {
            return $this->typeSet('union', [
                'null',
                $this->nodeType($type->type, $reflection) ?? '',
            ]);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return $this->typeSet(
                $type instanceof UnionType ? 'union' : 'intersection',
                array_values(array_map(
                    fn (Identifier|Name|IntersectionType $member): string => $this->nodeType($member, $reflection) ?? '',
                    $type->types,
                )),
            );
        }

        if ($type instanceof Identifier) {
            return strtolower($type->name);
        }

        if (!$type instanceof Name) {
            throw new \LogicException(sprintf('Unsupported parser type node %s.', $type::class));
        }

        $name = $type->toString();
        if (strtolower($name) === 'self') {
            return $reflection->getClosureScopeClass()?->getName() ?? 'self';
        }

        if (strtolower($name) === 'parent') {
            $parent = $reflection->getClosureScopeClass()?->getParentClass();

            return $parent === null || $parent === false ? 'parent' : $parent->getName();
        }

        return ltrim($name, '\\');
    }

    private function reflectionType(?ReflectionType $type, ReflectionFunction $reflection): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return $this->typeSet(
                $type instanceof ReflectionUnionType ? 'union' : 'intersection',
                array_values(array_map(
                    fn (ReflectionType $member): string => $this->reflectionType($member, $reflection) ?? '',
                    $type->getTypes(),
                )),
            );
        }

        if (!$type instanceof ReflectionNamedType) {
            return (string) $type;
        }

        $name = $type->isBuiltin()
            ? strtolower($type->getName())
            : ltrim($type->getName(), '\\');
        if ($name === 'self') {
            $name = $reflection->getClosureScopeClass()?->getName() ?? 'self';
        } elseif ($name === 'parent') {
            $parent = $reflection->getClosureScopeClass()?->getParentClass();
            $name = $parent === null || $parent === false ? 'parent' : $parent->getName();
        }

        if ($type->allowsNull() && !in_array($name, ['mixed', 'null'], true)) {
            return $this->typeSet('union', ['null', $name]);
        }

        return $name;
    }

    /** @param list<string> $members */
    private function typeSet(string $kind, array $members): string
    {
        sort($members);

        return sprintf('%s(%s)', $kind, implode(',', $members));
    }

    /**
     * @param array<array-key, Node\AttributeGroup> $nodeGroups
     * @param list<ReflectionAttribute<object>> $reflectionAttributes
     */
    private function attributesMatch(
        array $nodeGroups,
        array $reflectionAttributes,
        ReflectionFunction $reflection,
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

            $runtimeSyntax = self::reflectionAttributeArgumentSyntax($reflectionAttribute);
            if (count($nodeAttribute->args) !== count($runtimeSyntax)) {
                return false;
            }

            if ($nodeAttribute->args === []) {
                continue;
            }

            if (array_any($runtimeSyntax, self::reflectionExpressionMayExecute(...))) {
                throw ExportException::nonPortableClosure(
                    sprintf(
                        'attribute arguments for "%s" cannot be verified without executing user code',
                        $reflectionAttribute->getName(),
                    ),
                    $reflection->getFileName() ?: null,
                    $reflection->getStartLine() ?: null,
                );
            }

            $sourceArguments = [];
            foreach ($nodeAttribute->args as $argumentIndex => $argument) {
                $source = $this->sourceDefaultValue($argument->value);
                if (!$source['verifiable']) {
                    throw ExportException::nonPortableClosure(
                        sprintf(
                            'attribute arguments for "%s" cannot be verified safely',
                            $reflectionAttribute->getName(),
                        ),
                        $reflection->getFileName() ?: null,
                        $reflection->getStartLine() ?: null,
                    );
                }

                $sourceArguments[$argument->name?->toString() ?? $argumentIndex] = $source['value'];
            }

            if (!self::sameValue($sourceArguments, $reflectionAttribute->getArguments())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param ReflectionAttribute<object> $attribute
     * @return list<string>
     */
    private static function reflectionAttributeArgumentSyntax(ReflectionAttribute $attribute): array
    {
        $matches = [];
        if (preg_match_all('/^\s*Argument #\d+ \[ (.*) \]$/m', (string) $attribute, $matches) === false) {
            return [];
        }

        return $matches[1];
    }

    private function defaultMatches(
        ?Node\Expr $default,
        ReflectionParameter $parameter,
        ReflectionFunction $reflection,
    ): bool {
        if (($default !== null) !== $parameter->isDefaultValueAvailable()) {
            return false;
        }

        if ($default === null) {
            return true;
        }

        if ($parameter->isDefaultValueConstant()) {
            $runtimeName = $parameter->getDefaultValueConstantName();
            $sourceName = $this->constantDefaultName($default, $reflection);

            return is_string($runtimeName)
                && $sourceName !== null
                && ltrim($runtimeName, '\\') === ltrim($sourceName, '\\');
        }

        $sourceValue = $this->sourceDefaultValue($default);
        $runtimeSyntax = self::reflectionParameterDefaultSyntax($parameter);
        if (
            !$sourceValue['verifiable']
            || $runtimeSyntax === null
            || self::reflectionExpressionMayExecute($runtimeSyntax)
        ) {
            throw ExportException::nonPortableClosure(
                sprintf(
                    'parameter default for $%s cannot be verified without executing user code',
                    $parameter->getName(),
                ),
                $reflection->getFileName() ?: null,
                $reflection->getStartLine() ?: null,
            );
        }

        $runtimeValue = $parameter->getDefaultValue();
        if (is_float($runtimeValue) && is_float($sourceValue['value'])) {
            return is_nan($runtimeValue) && is_nan($sourceValue['value'])
                || pack('d', $runtimeValue) === pack('d', $sourceValue['value']);
        }

        return $runtimeValue === $sourceValue['value'];
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

        return $end < $start ? null : substr($signature, $start, $end - $start);
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

    private function constantDefaultName(Node\Expr $default, ReflectionFunction $reflection): ?string
    {
        if ($default instanceof ConstFetch) {
            $namespaced = $default->name->getAttribute('namespacedName');

            return $namespaced instanceof Name
                ? $namespaced->toString()
                : $default->name->toString();
        }

        if (!$default instanceof ClassConstFetch || !$default->class instanceof Name || !$default->name instanceof Identifier) {
            return null;
        }

        $class = $this->nodeType($default->class, $reflection);

        return $class === null ? null : $class . '::' . $default->name->name;
    }

    /** @return array{verifiable: bool, value: mixed} */
    private function sourceDefaultValue(Node\Expr $expression): array
    {
        if ($expression instanceof Int_ || $expression instanceof Float_ || $expression instanceof String_) {
            return ['verifiable' => true, 'value' => $expression->value];
        }

        if ($expression instanceof ConstFetch) {
            $value = match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $expression,
            };

            return $value === $expression
                ? ['verifiable' => false, 'value' => null]
                : ['verifiable' => true, 'value' => $value];
        }

        if ($expression instanceof UnaryMinus || $expression instanceof UnaryPlus) {
            $inner = $this->sourceDefaultValue($expression->expr);
            if (!$inner['verifiable'] || !is_int($inner['value']) && !is_float($inner['value'])) {
                return ['verifiable' => false, 'value' => null];
            }

            return [
                'verifiable' => true,
                'value' => $expression instanceof UnaryMinus ? -$inner['value'] : +$inner['value'],
            ];
        }

        if (!$expression instanceof Array_) {
            return ['verifiable' => false, 'value' => null];
        }

        $value = [];
        foreach ($expression->items as $item) {
            if ($item === null || $item->unpack || $item->byRef) {
                return ['verifiable' => false, 'value' => null];
            }

            $itemValue = $this->sourceDefaultValue($item->value);
            if (!$itemValue['verifiable']) {
                return ['verifiable' => false, 'value' => null];
            }

            if ($item->key === null) {
                $value[] = $itemValue['value'];

                continue;
            }

            $key = $this->sourceDefaultValue($item->key);
            if (!$key['verifiable'] || !is_int($key['value']) && !is_string($key['value'])) {
                return ['verifiable' => false, 'value' => null];
            }

            $value[$key['value']] = $itemValue['value'];
        }

        return ['verifiable' => true, 'value' => $value];
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

    private function captureMetadataMatches(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): bool {
        $usedVariables = $reflection->getClosureUsedVariables();
        $runtimeNames = array_keys($usedVariables);
        sort($runtimeNames);

        if ($node instanceof ArrowFunction) {
            return $this->arrowCaptureNames($node) === $runtimeNames;
        }

        $sourceNames = [];
        foreach ($node->uses as $use) {
            if (!is_string($use->var->name)) {
                return false;
            }

            $name = $use->var->name;
            if (!array_key_exists($name, $usedVariables)) {
                return false;
            }

            if ($use->byRef !== (ReflectionReference::fromArrayElement($usedVariables, $name) !== null)) {
                return false;
            }

            $sourceNames[] = $name;
        }

        sort($sourceNames);

        return $sourceNames === $runtimeNames;
    }

    private function nodeIsGenerator(ClosureNode|ArrowFunction $node): bool
    {
        return $this->ownScopeContains($node, Yield_::class)
            || $this->ownScopeContains($node, YieldFrom::class);
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

        if ($node instanceof Node\FunctionLike || $node instanceof ClassLike) {
            return false;
        }

        return $this->ownScopeContains($node, $nodeClass);
    }

    /** @return list<string> */
    private function arrowCaptureNames(ArrowFunction $node): array
    {
        $names = [];
        $this->collectReadVariableNames($node->expr, $names);

        foreach ([
            'GLOBALS',
            '_SERVER',
            '_GET',
            '_POST',
            '_FILES',
            '_COOKIE',
            '_SESSION',
            '_REQUEST',
            '_ENV',
        ] as $automaticGlobal) {
            unset($names[$automaticGlobal]);
        }

        foreach ($node->params as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                unset($names[$parameter->var->name]);
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param Node|array<mixed>|null $node
     * @param array<string, true> $names
     */
    private function collectReadVariableNames(Node|array|null $node, array &$names): void
    {
        if ($node === null) {
            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                if ($child instanceof Node) {
                    $this->collectReadVariableNames($child, $names);
                }
            }

            return;
        }

        if ($node instanceof Variable) {
            if (is_string($node->name)) {
                $names[$node->name] = true;
            } elseif ($node->name instanceof Node) {
                $this->collectReadVariableNames($node->name, $names);
            }

            return;
        }

        if ($node instanceof Assign || $node instanceof AssignRef) {
            $this->collectAssignmentTargetReads($node->var, $names);
            $this->collectReadVariableNames($node->expr, $names);

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
            foreach ($this->arrowCaptureNames($node) as $name) {
                $names[$name] = true;
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};
            if ($subNode instanceof Node || is_array($subNode)) {
                $this->collectReadVariableNames($subNode, $names);
            }
        }
    }

    /** @param array<string, true> $names */
    private function collectAssignmentTargetReads(Node\Expr $target, array &$names): void
    {
        if ($target instanceof Variable) {
            return;
        }

        if ($target instanceof Array_ || $target instanceof List_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->collectReadVariableNames($item->key, $names);
                    $this->collectAssignmentTargetReads($item->value, $names);
                }
            }

            return;
        }

        $this->collectReadVariableNames($target, $names);
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
        if ($scope === null) {
            return $node;
        }

        return new StaticCall(
            new FullyQualified(Closure::class),
            new Identifier('bind'),
            [
                new Node\Arg($node),
                new Node\Arg(new ConstFetch(new Name('null'))),
                new Node\Arg(new ClassConstFetch(
                    new FullyQualified($scope->getName()),
                    new Identifier('class'),
                )),
            ],
        );
    }
}
