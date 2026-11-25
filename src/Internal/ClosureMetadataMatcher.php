<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ExportException;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssignment;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\UnionType;
use ReflectionFunction;
use ReflectionAttribute;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionReference;
use ReflectionType;
use ReflectionUnionType;

/** @internal */
final class ClosureMetadataMatcher
{
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

    public function matches(
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
            $members = [];
            foreach ($type->types as $member) {
                $name = $this->nodeType($member, $reflection) ?? '';
                if ($type instanceof UnionType && $name === 'iterable') {
                    $members[] = 'Traversable';
                    $members[] = 'array';

                    continue;
                }

                $members[] = $name;
            }

            return $this->typeSet($type instanceof UnionType ? 'union' : 'intersection', $members);
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

            try {
                $runtimeArguments = $reflectionAttribute->getArguments();
            } catch (\Error $error) {
                throw new ExportException(
                    sprintf('Cannot verify runtime arguments for attribute "%s".', $reflectionAttribute->getName()),
                    ['attribute' => $reflectionAttribute->getName()],
                    $reflection->getFileName() ?: null,
                    $reflection->getStartLine() ?: null,
                    $error,
                );
            }

            if (!self::sameValue($sourceArguments, $runtimeArguments)) {
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

        try {
            $runtimeValue = $parameter->getDefaultValue();
        } catch (\Error | \ReflectionException $error) {
            throw new ExportException(
                sprintf('Cannot verify runtime default for parameter $%s.', $parameter->getName()),
                ['parameter' => $parameter->getName()],
                $reflection->getFileName() ?: null,
                $reflection->getStartLine() ?: null,
                $error,
            );
        }

        return self::sameValue($runtimeValue, $sourceValue['value']);
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
                if (array_key_exists(PHP_INT_MAX, $value)) {
                    return ['verifiable' => false, 'value' => null];
                }

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

    private function captureMetadataMatches(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): bool {
        $usedVariables = $reflection->getClosureUsedVariables();
        $runtimeNames = array_keys($usedVariables);
        sort($runtimeNames);

        if ($node instanceof ArrowFunction) {
            $required = $this->arrowCaptureNames($node);
            $possible = $this->arrowCaptureNames($node, includeOptional: true);

            return array_diff($required, $runtimeNames) === []
                && array_diff($runtimeNames, $possible) === [];
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
    private function arrowCaptureNames(ArrowFunction $node, bool $includeOptional = false): array
    {
        $names = [];
        $this->collectReadVariableNames($node->expr, $names, $includeOptional);

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
    private function collectReadVariableNames(Node|array|null $node, array &$names, bool $includeOptional = false): void
    {
        if ($node === null) {
            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                if ($child instanceof Node) {
                    $this->collectReadVariableNames($child, $names, $includeOptional);
                }
            }

            return;
        }

        if ($node instanceof Variable) {
            if (is_string($node->name)) {
                $names[$node->name] = true;
            } elseif ($node->name instanceof Node) {
                $this->collectReadVariableNames($node->name, $names, $includeOptional);
            }

            return;
        }

        if ($node instanceof Assign || $node instanceof AssignRef) {
            if ($includeOptional) {
                $this->collectReadVariableNames($node->var, $names, true);
            } else {
                $this->collectAssignmentTargetReads($node->var, $names);
            }
            $this->collectReadVariableNames($node->expr, $names, $includeOptional);

            return;
        }

        if (!$includeOptional && $node instanceof Isset_) {
            foreach ($node->vars as $variable) {
                $this->collectQuietAccessReads($variable, $names);
            }

            return;
        }

        if (!$includeOptional && $node instanceof Empty_) {
            $this->collectQuietAccessReads($node->expr, $names);

            return;
        }

        if (!$includeOptional && $node instanceof Coalesce) {
            $this->collectQuietAccessReads($node->left, $names);
            $this->collectReadVariableNames($node->right, $names);

            return;
        }

        if (!$includeOptional && $node instanceof CoalesceAssignment) {
            $this->collectQuietAccessReads($node->var, $names);
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
            foreach ($this->arrowCaptureNames($node, $includeOptional) as $name) {
                $names[$name] = true;
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};
            if ($subNode instanceof Node || is_array($subNode)) {
                $this->collectReadVariableNames($subNode, $names, $includeOptional);
            }
        }
    }

    /**
     * A quiet access may read a missing base variable without an undefined-variable warning.
     * Index and property-name expressions still have their ordinary read semantics.
     *
     * @param array<string, true> $names
     */
    private function collectQuietAccessReads(Node\Expr $target, array &$names): void
    {
        if ($target instanceof Variable && is_string($target->name)) {
            return;
        }

        if ($target instanceof ArrayDimFetch) {
            $this->collectQuietAccessReads($target->var, $names);
            $this->collectReadVariableNames($target->dim, $names);

            return;
        }

        if ($target instanceof PropertyFetch || $target instanceof NullsafePropertyFetch) {
            $this->collectQuietAccessReads($target->var, $names);
            $this->collectReadVariableNames($target->name, $names);

            return;
        }

        $this->collectReadVariableNames($target, $names);
    }

    /** @param array<string, true> $names */
    private function collectAssignmentTargetReads(Node\Expr $target, array &$names): void
    {
        if ($target instanceof Variable) {
            return;
        }

        if ($target instanceof ArrayDimFetch) {
            $this->collectAssignmentTargetReads($target->var, $names);
            $this->collectReadVariableNames($target->dim, $names);

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
}
