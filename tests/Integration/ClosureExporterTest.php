<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Integration;

use ArrayIterator;
use ArrayObject;
use Closure;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\Tests\Double\CallbackExporter;
use Componenta\VarExport\Tests\Fixture\BoundClosureFactory;
use Componenta\VarExport\Tests\Fixture\ClosureMarker;
use Componenta\VarExport\Tests\Fixture\ClosurePayloadAttribute;
use Componenta\VarExport\Tests\Fixture\DefaultConstructionProbe;
use Componenta\VarExport\Tests\Fixture\DeploymentStage;
use Componenta\VarExport\Tests\Fixture\LateStaticScopeChild;
use Componenta\VarExport\Tests\Fixture\PureDeploymentStage;
use Componenta\VarExport\Tests\Fixture\PropertyHookClosureFactory;
use Componenta\VarExport\Tests\Fixture\ScopedClosureFactory;
use Componenta\VarExport\Tests\Fixture\ScopedClosureTraitConsumer;
use Componenta\VarExport\Tests\Fixture\SignatureScopeBase;
use Componenta\VarExport\Tests\Fixture\SignatureScopeChild;
use Componenta\VarExport\Tests\Support\ObjectContextExpressionEvaluator;
use Componenta\VarExport\VarExport;
use DomainException;
use Generator;
use LogicException;
use ReflectionFunction;
use SensitiveParameter;
use Throwable;

use function Componenta\VarExport\Tests\Fixture\assignmentOnlyArrowClosure;
use function Componenta\VarExport\Tests\Fixture\attributedClosure;
use function Componenta\VarExport\Tests\Fixture\blockClosure;
use function Componenta\VarExport\Tests\Fixture\byReferenceCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\capturedClosure;
use function Componenta\VarExport\Tests\Fixture\capturedValueClosure;
use function Componenta\VarExport\Tests\Fixture\complexDefaultClosure;
use function Componenta\VarExport\Tests\Fixture\classConstantDefaultClosure;
use function Componenta\VarExport\Tests\Fixture\closedResourceCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\evalClosure;
use function Componenta\VarExport\Tests\Fixture\dnfSignatureClosure;
use function Componenta\VarExport\Tests\Fixture\destructuringArrowClosure;
use function Componenta\VarExport\Tests\Fixture\keyedDestructuringArrowClosure;
use function Componenta\VarExport\Tests\Fixture\generatorClosure;
use function Componenta\VarExport\Tests\Fixture\automaticGlobalsClosure;
use function Componenta\VarExport\Tests\Fixture\heredocClosure;
use function Componenta\VarExport\Tests\Fixture\globalConstantDefaultClosure;
use function Componenta\VarExport\Tests\Fixture\includeClosure;
use function Componenta\VarExport\Tests\Fixture\importedClassClosure;
use function Componenta\VarExport\Tests\Fixture\importedConstantClosure;
use function Componenta\VarExport\Tests\Fixture\importedFunctionClosure;
use function Componenta\VarExport\Tests\Fixture\namedCallableClosure;
use function Componenta\VarExport\Tests\Fixture\multilineLiteralClosure;
use function Componenta\VarExport\Tests\Fixture\nestedByReferenceCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\nestedClosureCapture;
use function Componenta\VarExport\Tests\Fixture\nestedArrowCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\nestedGeneratorClosure;
use function Componenta\VarExport\Tests\Fixture\nestedStaticLocalClosure;
use function Componenta\VarExport\Tests\Fixture\nonStaticCapturedClosure;
use function Componenta\VarExport\Tests\Fixture\nonStaticUnboundClosure;
use function Componenta\VarExport\Tests\Fixture\nowdocClosure;
use function Componenta\VarExport\Tests\Fixture\objectCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\portableConstantClosure;
use function Componenta\VarExport\Tests\Fixture\portableFunctionClosure;
use function Componenta\VarExport\Tests\Fixture\recursiveArrayCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\referenceSignatureClosure;
use function Componenta\VarExport\Tests\Fixture\requireClosure;
use function Componenta\VarExport\Tests\Fixture\resourceCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\simpleClosure;
use function Componenta\VarExport\Tests\Fixture\safeAttributedClosure;
use function Componenta\VarExport\Tests\Fixture\safeLiteralDefaultsClosure;
use function Componenta\VarExport\Tests\Fixture\sourceDirectoryClosure;
use function Componenta\VarExport\Tests\Fixture\sourceEnvironmentClosure;
use function Componenta\VarExport\Tests\Fixture\sourceFileClosure;
use function Componenta\VarExport\Tests\Fixture\sourceLocalConstantClosure;
use function Componenta\VarExport\Tests\Fixture\staticLocalClosure;
use function Componenta\VarExport\Tests\Fixture\statefulCaptureClosure;
use function Componenta\VarExport\Tests\Fixture\unionSignatureClosure;
use function Componenta\VarExport\Tests\Fixture\unverifiableAttributedClosure;
use function Componenta\VarExport\Tests\Fixture\anonymousScopeClosure;
use function Componenta\VarExport\Tests\Support\createTemporaryPhpFile;
use function Componenta\VarExport\Tests\Support\evaluateClosureExpression;
use function Componenta\VarExport\Tests\Support\overwriteFile;
use function Componenta\VarExport\Tests\Support\requireClosureFile;

it('returns null for values that are not closures without requiring a binding', function (): void {
    expect((new ClosureExporter())->export('not a closure'))->toBeNull();
});

it('requires direct closure exporters to be immutably bound first', function (): void {
    $exporter = new ClosureExporter();
    $bound = $exporter->withVarExport(VarExport::withDefaults());

    $expression = \Componenta\VarExport\Tests\Support\requireExpression($bound->export(simpleClosure()));

    expect($bound)->not->toBe($exporter)
        ->and(fn (): ?string => $exporter->export(simpleClosure()))->toThrow(ExportException::class)
        ->and(evaluateClosureExpression($expression)(41))->toBe(42);
});

it('exports a deterministic executable arrow-function expression', function (): void {
    $exporter = VarExport::withDefaults();
    $expression = $exporter->export(simpleClosure());
    $restored = evaluateClosureExpression($expression);

    expect($expression)->toBe($exporter->export(simpleClosure()))
        ->not->toContain('<?php')
        ->and($restored(41))->toBe(42);
});

it('exports a deterministic executable block closure', function (): void {
    $exporter = VarExport::withDefaults();
    $expression = $exporter->export(blockClosure());
    $restored = evaluateClosureExpression($expression);

    expect($expression)->toBe($exporter->export(blockClosure()))
        ->not->toContain('<?php')
        ->and($restored(20))->toBe(42);
});

it('inlines safe scalar enum and array captures into a self-contained expression', function (): void {
    $expression = VarExport::withDefaults()->export(capturedClosure());
    $restored = evaluateClosureExpression($expression);

    expect($restored(7))->toBe([
        'item:7',
        ['enabled' => true, 'ports' => [80, 443]],
        DeploymentStage::Testing,
    ])
        ->and($expression)->toContain('$prefix = "item:";')
        ->and($expression)->toContain('\\Componenta\\VarExport\\Tests\\Fixture\\DeploymentStage::Testing');
});

it('freezes every supported capture type', function (mixed $value): void {
    $expression = VarExport::withDefaults()->export(capturedValueClosure($value));
    $restored = evaluateClosureExpression($expression);

    expect($restored())->toBe($value);
})->with([
    'null' => [null],
    'boolean' => [false],
    'integer' => [42],
    'minimum integer' => [PHP_INT_MIN],
    'float' => [0.5],
    'binary string' => ["value\0\xFF"],
    'backed enum' => [DeploymentStage::Production],
    'pure enum' => [PureDeploymentStage::Local],
    'nested mixed array' => [['name' => 'componenta', 7 => [true, 0.25]]],
    'minimum integer array key' => [[PHP_INT_MIN => 'minimum']],
]);

it('freezes positive and negative infinity captures', function (float $value): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(capturedValueClosure($value)),
    );

    expect($restored())->toBe($value);
})->with([
    'positive infinity' => [INF],
    'negative infinity' => [-INF],
]);

it('freezes NAN captures', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(capturedValueClosure(NAN)),
    );
    $value = $restored();
    if (!is_float($value)) {
        throw new LogicException('Expected the restored capture to be a float.');
    }

    expect(is_nan($value))->toBeTrue();
});

it('preserves negative signed zero when freezing a capture', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(capturedValueClosure(-0.0)),
    );
    $value = $restored();
    if (!is_float($value)) {
        throw new LogicException('Expected the restored capture to be a float.');
    }

    expect(pack('d', $value))->toBe(pack('d', -0.0));
});

it('preserves by-value capture mutation semantics across invocations', function (): void {
    $original = statefulCaptureClosure();
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));

    expect($restored())->toBe(1)
        ->and($restored())->toBe(1)
        ->and($original())->toBe(1)
        ->and($original())->toBe(1);
});

it('allows assignment-only arrow variables that are not captures', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(assignmentOnlyArrowClosure()),
    );

    expect($restored())->toBe(42);
});

it('isolates a non-static closure that is not bound to this', function (): void {
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export(nonStaticUnboundClosure()));

    expect($restored(41))->toBe(42);
});

it('restores a safe named class scope', function (): void {
    $expression = VarExport::withDefaults()->export(ScopedClosureFactory::make());
    $restored = evaluateClosureExpression($expression);

    expect((new ReflectionFunction($restored))->getClosureScopeClass()?->getName())->toBe(ScopedClosureFactory::class)
        ->and($restored(41))->toBe(42);
});

it('restores the consuming class scope for a closure declared in a trait', function (): void {
    $expression = VarExport::withDefaults()->export(ScopedClosureTraitConsumer::makeFromTrait());
    $restored = evaluateClosureExpression($expression);
    $reflection = new ReflectionFunction($restored);

    expect($reflection->getClosureScopeClass()?->getName())->toBe(ScopedClosureTraitConsumer::class)
        ->and($restored(41))->toBe(42);
});

it('restores closures created inside a PHP 8.4 property hook', function (): void {
    $expression = VarExport::withDefaults()->export((new PropertyHookClosureFactory())->callback);
    $restored = evaluateClosureExpression($expression);

    expect($restored(41))->toBe(42);
});

it('isolates non-static closures from the object evaluating the expression', function (
    Closure $closure,
    array $arguments,
    mixed $expected,
    ?string $expectedScope,
): void {
    $expression = VarExport::withDefaults()->export($closure);
    $restored = new ObjectContextExpressionEvaluator()->evaluateClosure($expression);
    $reflection = new ReflectionFunction($restored);

    expect($reflection->getClosureThis())->toBeNull()
        ->and($reflection->getClosureScopeClass()?->getName())->toBe($expectedScope)
        ->and($reflection->getClosureCalledClass()?->getName())->toBe($expectedScope)
        ->and($restored(...$arguments))->toBe($expected);
})->with([
    'static without captures' => [simpleClosure(), [41], 42, null],
    'static with captures' => [capturedValueClosure(42), [], 42, null],
    'without captures' => [nonStaticUnboundClosure(), [41], 42, null],
    'with a frozen capture' => [nonStaticCapturedClosure(), [], 42, null],
    'with a named class scope' => [
        ScopedClosureFactory::makeNonStatic(),
        [41],
        42,
        ScopedClosureFactory::class,
    ],
]);

it('rejects a closure whose late-static called scope differs from its lexical scope', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(LateStaticScopeChild::make()))
        ->toThrow(ExportException::class, 'class scope');
});

it('rejects loading an exported closure under a different strict-types mode', function (
    int $sourceStrictTypes,
    int $targetStrictTypes,
): void {
    $provider = createTemporaryPhpFile(
        'componenta-closure-strict-provider-',
        "<?php declare(strict_types={$sourceStrictTypes}); return static fn (): int => '3';",
    );
    $generated = null;

    try {
        $closure = requireClosureFile($provider);
        $expression = VarExport::withDefaults()->export($closure);
        $generated = createTemporaryPhpFile(
            'componenta-closure-strict-target-',
            "<?php declare(strict_types={$targetStrictTypes}); return {$expression};",
        );

        expect(fn (): Closure => requireClosureFile($generated))
            ->toThrow(ExportException::class, 'strict_types');
    } finally {
        unlink($provider);
        if ($generated !== null && is_file($generated)) {
            unlink($generated);
        }
    }
})->with([
    'strict source and coercive target' => [1, 0],
    'coercive source and strict target' => [0, 1],
]);

it('preserves closure behavior when source and target strict-types modes match', function (
    int $strictTypes,
): void {
    $provider = createTemporaryPhpFile(
        'componenta-closure-matching-strict-provider-',
        "<?php declare(strict_types={$strictTypes}); return static fn (): int => '3';",
    );
    $generated = null;

    try {
        $closure = requireClosureFile($provider);
        $expression = VarExport::withDefaults()->export($closure);
        $generated = createTemporaryPhpFile(
            'componenta-closure-matching-strict-target-',
            "<?php declare(strict_types={$strictTypes}); return {$expression};",
        );
        $restored = requireClosureFile($generated);

        if ($strictTypes === 1) {
            expect(fn (): mixed => $restored())->toThrow(\TypeError::class);
        } else {
            expect($restored())->toBe(3);
        }
    } finally {
        unlink($provider);
        if ($generated !== null && is_file($generated)) {
            unlink($generated);
        }
    }
})->with([
    'strict source and target' => [1],
    'coercive source and target' => [0],
]);

it('rejects source compilation directives that cannot be preserved in an expression', function (
    string $source,
): void {
    $path = createTemporaryPhpFile('componenta-closure-declare-', $source);

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'declare(ticks)');
    } finally {
        unlink($path);
    }
})->with([
    'file-wide ticks' => [
        '<?php declare(ticks=1); return static fn (): int => 42;',
    ],
    'scoped ticks' => [
        '<?php declare(ticks=1) { return static fn (): int => 42; }',
    ],
    'earlier namespace with semicolon declaration' => [
        '<?php namespace ComponentaTicksFirst; declare(ticks=1); namespace ComponentaTicksSecond; return static function (): int { $value = 41; return $value + 1; };',
    ],
    'earlier bracketed namespace with semicolon declaration' => [
        '<?php namespace ComponentaTicksThird { declare(ticks=1); } namespace ComponentaTicksFourth { return static function (): int { $value = 41; return $value + 1; }; }',
    ],
    'earlier function with semicolon declaration' => [
        '<?php $unrelated = static function (): void { declare(ticks=1); }; return static function (): int { $value = 41; return $value + 1; };',
    ],
    'earlier conditional with semicolon declaration' => [
        '<?php if (false) { declare(ticks=1); } return static function (): int { $value = 41; return $value + 1; };',
    ],
]);

it('ignores source compilation directives that do not affect the closure', function (
    string $source,
): void {
    $path = createTemporaryPhpFile('componenta-closure-unrelated-declare-', $source);

    try {
        $closure = requireClosureFile($path);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($closure));

        expect($restored())->toBe(42);
    } finally {
        unlink($path);
    }
})->with([
    'later file-wide ticks' => [<<<'PHP'
<?php
declare(strict_types=1);
$closure = static fn (): int => 42;
declare(ticks=1);

return $closure;
PHP,
    ],
    'scoped ticks inside an unrelated closure' => [<<<'PHP'
<?php
declare(strict_types=1);
$unrelated = static function (): int {
    declare(ticks=1) {
        return 1;
    }
};

return static fn (): int => 42;
PHP,
    ],
    'semicolon declaration in a completed scoped declaration' => [
        '<?php declare(strict_types=1); declare(ticks=1) { declare(ticks=2); } return static function (): int { $value = 41; return $value + 1; };',
    ],
    'ticks reset before closure' => [
        '<?php declare(strict_types=1); declare(ticks=1); declare(ticks=0); return static function (): int { $value = 41; return $value + 1; };',
    ],
    'scoped ticks reset around closure' => [
        '<?php declare(strict_types=1); declare(ticks=1); declare(ticks=0) { return static function (): int { $value = 41; return $value + 1; }; }',
    ],
]);

it('preserves source compilation directives contained inside the closure expression', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-contained-declare-',
        <<<'PHP'
<?php
declare(strict_types=1);

return static function (): int {
    declare(ticks=1) {
        $value = 40;
        ++$value;

        return $value + 1;
    }
};
PHP,
    );

    try {
        $original = requireClosureFile($path);
        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($original));
        $ticks = 0;
        $tickHandler = static function () use (&$ticks): void {
            ++$ticks;
        };
        register_tick_function($tickHandler);

        try {
            $originalResult = $original();
            $originalTicks = $ticks;
            $ticks = 0;
            $restoredResult = $restored();

            expect($originalTicks)->toBeGreaterThan(0)
                ->and($restoredResult)->toBe($originalResult)
                ->and($ticks)->toBe($originalTicks);
        } finally {
            unregister_tick_function($tickHandler);
        }
    } finally {
        unlink($path);
    }
});

it('resolves portable global functions and constants', function (): void {
    $function = evaluateClosureExpression(VarExport::withDefaults()->export(portableFunctionClosure()));
    $constant = evaluateClosureExpression(VarExport::withDefaults()->export(portableConstantClosure()));

    expect($function('componenta'))->toBe('COMPONENTA')
        ->and($constant())->toBe(PHP_INT_MAX);
});

it('freezes imported function and constant aliases to fully qualified symbols', function (): void {
    $functionExpression = VarExport::withDefaults()->export(importedFunctionClosure());
    $constantExpression = VarExport::withDefaults()->export(importedConstantClosure());
    $function = evaluateClosureExpression($functionExpression);
    $constant = evaluateClosureExpression($constantExpression);

    expect($functionExpression)->toContain('\\strtoupper($value)')
        ->and($constantExpression)->toContain('\\PHP_INT_MAX')
        ->and($function('componenta'))->toBe('COMPONENTA')
        ->and($constant())->toBe(PHP_INT_MAX);
});

it('preserves PHP automatic globals without treating them as captures', function (): void {
    $key = 'COMPONENTA_VAR_EXPORT_RUNTIME_' . bin2hex(random_bytes(6));
    $GLOBALS[$key] = 'runtime';
    $_SERVER[$key] = 'runtime';
    $_GET[$key] = 'runtime';
    $_POST[$key] = 'runtime';
    $_FILES[$key] = 'runtime';
    $_COOKIE[$key] = 'runtime';
    $_SESSION[$key] = 'runtime';
    $_REQUEST[$key] = 'runtime';
    $_ENV[$key] = 'runtime';

    try {
        $restored = evaluateClosureExpression(
            VarExport::withDefaults()->export(automaticGlobalsClosure()),
        );

        expect($restored($key))->toBe(array_fill(0, 9, 'runtime'));
    } finally {
        unset(
            $GLOBALS[$key],
            $_SERVER[$key],
            $_GET[$key],
            $_POST[$key],
            $_FILES[$key],
            $_COOKIE[$key],
            $_SESSION[$key],
            $_REQUEST[$key],
            $_ENV[$key],
        );
    }
});

it('round-trips nullable union, DNF and class-constant-default signatures', function (): void {
    $union = evaluateClosureExpression(VarExport::withDefaults()->export(unionSignatureClosure()));
    $dnf = evaluateClosureExpression(VarExport::withDefaults()->export(dnfSignatureClosure()));
    $default = evaluateClosureExpression(VarExport::withDefaults()->export(classConstantDefaultClosure()));
    $iterator = new ArrayIterator([1, 2]);

    expect($union())->toBeNull()
        ->and($union('value'))->toBe('value')
        ->and($dnf($iterator))->toBe($iterator)
        ->and($dnf(['value']))->toBe(['value'])
        ->and($default())->toBe(7);
});

it('round-trips global-constant parameter defaults', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(globalConstantDefaultClosure()),
    );

    expect($restored())->toBe(PHP_INT_MAX);
});

it('round-trips safe literal parameter defaults without changing their values', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(safeLiteralDefaultsClosure()),
    );
    $defaults = $restored();
    if (!is_array($defaults)) {
        throw new LogicException('Expected the restored closure defaults to produce an array.');
    }

    expect($defaults)->toHaveCount(6)
        ->and($defaults[0])->toBeNull()
        ->and($defaults[1])->toBeTrue()
        ->and($defaults[2])->toBe(-7)
        ->and(pack('d', $defaults[3]))->toBe(pack('d', -0.0))
        ->and($defaults[4])->toBe('componenta')
        ->and($defaults[5])->toBe(['ports' => [80, 443], 'enabled' => false]);
});

it('round-trips nullable self and parent signature types', function (): void {
    $nullable = evaluateClosureExpression(VarExport::withDefaults()->export(SignatureScopeChild::nullable()));
    $self = evaluateClosureExpression(VarExport::withDefaults()->export(SignatureScopeChild::selfType()));
    $parent = evaluateClosureExpression(VarExport::withDefaults()->export(SignatureScopeChild::parentType()));
    $child = new SignatureScopeChild();
    $base = new SignatureScopeBase();

    expect($nullable())->toBeNull()
        ->and($nullable(42))->toBe(42)
        ->and($self($child))->toBe($child)
        ->and($parent($base))->toBe($base);
});

it('preserves reference parameters, reference returns and variadic parameters', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(referenceSignatureClosure()),
    );
    $value = 40;

    $reference = &$restored($value, 'first', 'second');
    $reference = 7;

    expect($value)->toBe(7);
});

it('round-trips generator bodies and imported class names', function (): void {
    $generator = evaluateClosureExpression(VarExport::withDefaults()->export(generatorClosure()));
    $factory = evaluateClosureExpression(VarExport::withDefaults()->export(importedClassClosure()));
    $generatedValues = $generator(41);

    if (!$generatedValues instanceof Generator) {
        throw new LogicException('Expected restored generator closure to return a Generator.');
    }

    expect(iterator_to_array($generatedValues, false))->toBe([41, 42])
        ->and($factory(['value']))->toEqual(new ArrayObject(['value']));
});

it('keeps nested generators out of the outer closure metadata and preserves yield from', function (): void {
    $outer = evaluateClosureExpression(VarExport::withDefaults()->export(nestedGeneratorClosure()));
    $inner = $outer();
    if (!$inner instanceof Closure) {
        throw new LogicException('Expected restored outer closure to return a Closure.');
    }

    $values = $inner();
    if (!$values instanceof Generator) {
        throw new LogicException('Expected restored inner closure to return a Generator.');
    }

    expect(iterator_to_array($values, false))->toBe([41, 42]);
});

it('freezes captures used only by a nested arrow function', function (): void {
    $outer = evaluateClosureExpression(VarExport::withDefaults()->export(nestedArrowCaptureClosure()));
    $inner = $outer();

    expect($inner)->toBeInstanceOf(Closure::class);
    if (!$inner instanceof Closure) {
        throw new LogicException('Expected restored outer closure to return a Closure.');
    }

    expect($inner())->toBe(42);
});

it('does not treat destructuring assignment targets as arrow captures', function (): void {
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export(destructuringArrowClosure()));

    expect($restored())->toBe([1, 2]);
});

it('freezes variables read as destructuring keys in arrow functions', function (): void {
    $restored = evaluateClosureExpression(
        VarExport::withDefaults()->export(keyedDestructuringArrowClosure()),
    );

    expect($restored())->toBe(['answer' => 42]);
});

it('round-trips multiline string literals in closure bodies without changing their bytes', function (
    Closure $closure,
    string $expected,
): void {
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export($closure));

    expect($restored())->toBe($expected);
})->with([
    'quoted multiline string' => [multilineLiteralClosure(), "first\n    second\n        third"],
    'heredoc' => [heredocClosure(), "SELECT id, name\nFROM users\nWHERE active = 1"],
    'nowdoc' => [nowdocClosure(), "{{ greeting }},\n    welcome to \$site"],
]);

it('preserves closure and parameter attributes', function (): void {
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export(attributedClosure()));
    $reflection = new ReflectionFunction($restored);
    $closureAttributes = $reflection->getAttributes(ClosureMarker::class);
    $parameterAttributes = $reflection->getParameters()[0]->getAttributes(SensitiveParameter::class);

    expect($restored())->toBe('value')
        ->and($closureAttributes)->toHaveCount(1)
        ->and($closureAttributes[0]->newInstance()->name)->toBe('portable')
        ->and($parameterAttributes)->toHaveCount(1);
});

it('preserves named safe array and signed-zero attribute arguments', function (): void {
    $restored = evaluateClosureExpression(VarExport::withDefaults()->export(safeAttributedClosure()));
    $attributes = new ReflectionFunction($restored)->getAttributes(ClosurePayloadAttribute::class);

    expect($attributes)->toHaveCount(1);

    $value = $attributes[0]->newInstance()->value;
    if (!is_array($value)) {
        throw new LogicException('Expected the restored attribute value to be an array.');
    }

    expect($value['enabled'] ?? null)->toBeTrue()
        ->and(pack('d', $value['signed_zero'] ?? 1.0))->toBe(pack('d', -0.0));
});

it('rejects parameter defaults that cannot be verified without executing user code', function (): void {
    DefaultConstructionProbe::$constructions = 0;

    expect(fn (): string => VarExport::withDefaults()->export(complexDefaultClosure()))
        ->toThrow(ExportException::class, 'parameter default')
        ->and(DefaultConstructionProbe::$constructions)->toBe(0);
});

it('rejects unverifiable attribute arguments without executing user code', function (): void {
    DefaultConstructionProbe::$constructions = 0;

    expect(fn (): string => VarExport::withDefaults()->export(unverifiableAttributedClosure()))
        ->toThrow(ExportException::class, 'attribute arguments')
        ->and(DefaultConstructionProbe::$constructions)->toBe(0);
});

it('rejects captures by reference', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(byReferenceCaptureClosure()))
        ->toThrow(ExportException::class, 'reference');
});

it('rejects captures by reference inside nested closures', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(nestedByReferenceCaptureClosure()))
        ->toThrow(ExportException::class, 'reference');
});

it('rejects recursive references nested in captured arrays', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(recursiveArrayCaptureClosure()))
        ->toThrow(ExportException::class, 'reference');
});

it('rejects a closure bound to this', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export((new BoundClosureFactory())->make()))
        ->toThrow(ExportException::class, '$this');
});

it('rejects an unbound closure that refers to this', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-this-',
        '<?php return function (): bool { return isset($this); };',
    );

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, '$this');
    } finally {
        unlink($path);
    }
});

it('rejects dynamic variable access that cannot be frozen', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-dynamic-variable-',
        '<?php $name = "runtime"; return static fn (): mixed => ${$name};',
    );

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'dynamic variable');
    } finally {
        unlink($path);
    }
});

it('rejects static local state', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(staticLocalClosure()))
        ->toThrow(ExportException::class, 'static local');
});

it('rejects static local state inside nested closures', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(nestedStaticLocalClosure()))
        ->toThrow(ExportException::class, 'static local');
});

it('rejects object captures', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(objectCaptureClosure()))
        ->toThrow(ExportException::class, 'object');
});

it('rejects resource captures', function (): void {
    [$closure, $resource] = resourceCaptureClosure();

    try {
        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'resource');
    } finally {
        fclose($resource);
    }
});

it('rejects Closure captures', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(nestedClosureCapture()))
        ->toThrow(ExportException::class, 'Closure');
});

it('rejects closed resource captures', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(closedResourceCaptureClosure()))
        ->toThrow(ExportException::class, 'resource');
});

it('accepts capture nesting at the safety boundary and rejects the next level', function (): void {
    $allowed = 'leaf';
    for ($depth = 0; $depth < 63; ++$depth) {
        $allowed = [$allowed];
    }
    $allowedClosure = static fn (): mixed => $allowed;

    $restored = evaluateClosureExpression(VarExport::withDefaults()->export($allowedClosure));

    expect($restored())->toBe($allowed);

    $rejected = 'leaf';
    for ($depth = 0; $depth < 64; ++$depth) {
        $rejected = [$rejected];
    }
    $rejectedClosure = static fn (): mixed => $rejected;

    expect(fn (): string => VarExport::withDefaults()->export($rejectedClosure))
        ->toThrow(ExportException::class, 'maximum depth');
});

it('rejects named callables', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(namedCallableClosure()))
        ->toThrow(ExportException::class, 'named callable');
});

it('rejects eval include and require', function (Closure $closure, string $expected): void {
    expect(fn (): string => VarExport::withDefaults()->export($closure))
        ->toThrow(ExportException::class, $expected);
})->with([
    'eval' => [evalClosure(), 'eval'],
    'include' => [includeClosure(), 'include/require'],
    'require' => [requireClosure(), 'include/require'],
]);

it('rejects PHP mode switches inside closure bodies', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-inline-html-',
        <<<'PHP'
<?php
declare(strict_types=1);

return static function (): int {
    // ?>
inline output
<?php
    return 42;
};
PHP,
    );

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'PHP mode switches');
    } finally {
        unlink($path);
    }
});

it('rejects source-path and source-environment dependencies', function (Closure $closure, string $expected): void {
    expect(fn (): string => VarExport::withDefaults()->export($closure))
        ->toThrow(ExportException::class, $expected);
})->with([
    '__FILE__' => [sourceFileClosure(), '__FILE__'],
    '__DIR__' => [sourceDirectoryClosure(), '__DIR__'],
    'source-local function' => [sourceEnvironmentClosure(), 'provider source file'],
    'source-local constant' => [sourceLocalConstantClosure(), 'user-defined constant'],
]);

it('rejects unresolved unqualified symbols with namespace fallback semantics', function (
    string $body,
    string $expected,
): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-unresolved-symbol-',
        "<?php declare(strict_types=1); namespace Componenta\\VarExport\\Tests\\Temporary; return static fn (): mixed => {$body};",
    );

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, $expected);
    } finally {
        unlink($path);
    }
})->with([
    'function' => ['componenta_var_export_missing_function_7e2b()', 'unresolved unqualified function'],
    'constant' => ['COMPONENTA_VAR_EXPORT_MISSING_CONSTANT_7E2B', 'unresolved unqualified constant'],
]);

it('preserves explicitly qualified symbols without resolving them during export', function (
    string $body,
    string $expected,
): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-qualified-symbol-',
        "<?php declare(strict_types=1); namespace Componenta\\VarExport\\Tests\\Temporary; return static fn (): mixed => {$body};",
    );

    try {
        $closure = requireClosureFile($path);
        $expression = VarExport::withDefaults()->export($closure);

        expect($expression)->toContain($expected);
    } finally {
        unlink($path);
    }
})->with([
    'function' => ['\\componenta_var_export_future_function_7e2b()', '\\componenta_var_export_future_function_7e2b()'],
    'constant' => ['\\COMPONENTA_VAR_EXPORT_FUTURE_CONSTANT_7E2B', '\\COMPONENTA_VAR_EXPORT_FUTURE_CONSTANT_7E2B'],
    'namespace-relative function' => [
        'namespace\\componenta_var_export_future_function_7e2b()',
        '\\Componenta\\VarExport\\Tests\\Temporary\\componenta_var_export_future_function_7e2b()',
    ],
    'namespace-relative constant' => ['namespace\\FUTURE_CONSTANT_7E2B', '\\Componenta\\VarExport\\Tests\\Temporary\\FUTURE_CONSTANT_7E2B'],
]);

it('rejects source-context magic constants other than file and directory', function (string $magicConstant): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-magic-',
        "<?php return static fn (): mixed => {$magicConstant};",
    );

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, $magicConstant);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
})->with([
    '__LINE__',
    '__FUNCTION__',
    '__METHOD__',
    '__CLASS__',
    '__TRAIT__',
    '__NAMESPACE__',
]);

it('rejects nested named declarations', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-nested-declaration-',
        <<<'PHP'
<?php

return static function (): int {
    function componentaVarExportNestedFunction(): int
    {
        return 42;
    }

    return 42;
};
PHP,
    );

    try {
        $closure = requireClosureFile($path);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'nested named declarations');
    } finally {
        unlink($path);
    }
});

it('rejects anonymous class scope', function (): void {
    expect(fn (): string => VarExport::withDefaults()->export(anonymousScopeClosure()))
        ->toThrow(ExportException::class, 'class scope');
});

it('rejects eval-created closures without readable source', function (): void {
    $closure = eval('return static fn (): int => 42;');
    if (!$closure instanceof Closure) {
        throw new LogicException('Expected eval() to create a Closure.');
    }

    expect(fn (): string => VarExport::withDefaults()->export($closure))
        ->toThrow(ExportException::class, 'source file');
});

it('rejects invalid, empty, stale and ambiguous source after the closure was loaded', function (
    string $replacement,
    string $expected,
): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-source-',
        '<?php return static fn (int $value): int => $value;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, $replacement);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, $expected);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
})->with([
    'invalid source' => ['<?php static fn (', 'parse'],
    'empty source' => ['', 'found 0'],
    'stale source' => ['<?php return static fn (): int => 1;', 'no longer matches'],
    'stale parameter name' => [
        '<?php return static fn (int $renamed): int => $renamed;',
        'no longer matches',
    ],
    'ambiguous source' => ['<?php return [static fn (int $value): int => 1, static fn (int $value): int => 2];', 'found 2'],
]);

it('rejects source whose captured variables changed after the closure was created', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-captures-',
        '<?php $before = "value"; return static fn (): string => $before;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, '<?php $after = "value"; return static fn (): string => $after;');

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('rejects stale arrow source that adds a captured variable', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-added-capture-',
        '<?php $before = "a"; return static fn (): string => $before;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile(
            $path,
            '<?php $before = "a"; $after = "b"; return static fn (): string => $before . $after;',
        );

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
});

it('rejects stale source whose runtime metadata changed', function (
    string $original,
    string $replacement,
): void {
    $path = createTemporaryPhpFile('componenta-closure-metadata-', $original);

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, $replacement);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
})->with([
    'generator state' => [
        '<?php return static function (): iterable { return []; };',
        '<?php return static function (): iterable { yield 1; };',
    ],
    'static local state' => [
        '<?php return static function (): int { return 1; };',
        '<?php return static function (): int { static $value = 1; return $value; };',
    ],
    'capture reference mode' => [
        '<?php $value = 1; return static function () use ($value): int { return $value; };',
        '<?php $value = 1; return static function () use (&$value): int { return $value; };',
    ],
]);

it('rejects stale closure and parameter attributes', function (bool $parameterAttribute): void {
    $suffix = bin2hex(random_bytes(6));
    $first = 'ComponentaClosureAttributeA' . $suffix;
    $second = 'ComponentaClosureAttributeB' . $suffix;
    $target = $parameterAttribute ? 'TARGET_PARAMETER' : 'TARGET_FUNCTION';
    $originalClosure = $parameterAttribute
        ? "static fn (#[{$first}] int \$value): int => \$value"
        : "#[{$first}] static fn (int \$value): int => \$value";
    $replacementClosure = $parameterAttribute
        ? "static fn (#[{$second}] int \$value): int => \$value"
        : "#[{$second}] static fn (int \$value): int => \$value";
    $declarations = "#[\\Attribute(\\Attribute::{$target})] class {$first} {} "
        . "#[\\Attribute(\\Attribute::{$target})] class {$second} {} ";
    $path = createTemporaryPhpFile(
        'componenta-closure-attribute-',
        "<?php {$declarations} return {$originalClosure};",
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, "<?php {$declarations} return {$replacementClosure};");

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
})->with([
    'closure attribute' => [false],
    'parameter attribute' => [true],
]);

it('rejects stale scalar attribute arguments', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'ComponentaClosureArgumentAttribute' . $suffix;
    $declaration = "#[\\Attribute(\\Attribute::TARGET_FUNCTION)] class {$attribute} { "
        . 'public function __construct(public int $value) {} } ';
    $path = createTemporaryPhpFile(
        'componenta-closure-attribute-argument-',
        "<?php {$declaration} return #[{$attribute}(1)] static fn (): int => 42;",
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile(
            $path,
            "<?php {$declaration} return #[{$attribute}(2)] static fn (): int => 42;",
        );

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
});

it('rejects stale source moved to another lexical class owner', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $originalOwner = 'ComponentaClosureOwnerA' . $suffix;
    $replacementOwner = 'ComponentaClosureOwnerB' . $suffix;
    $source = static fn (string $owner): string => "<?php class {$owner} { "
        . 'public static function make(): \\Closure { return static fn (): int => 42; } '
        . "} return {$owner}::make();";
    $path = createTemporaryPhpFile('componenta-closure-owner-', $source($originalOwner));

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, $source($replacementOwner));

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
});

it('rejects stale source whose lexical callable owner changed', function (
    string $original,
    string $replacement,
): void {
    $suffix = bin2hex(random_bytes(6));
    $original = str_replace('{suffix}', $suffix, $original);
    $replacement = str_replace('{suffix}', $suffix, $replacement);
    $path = createTemporaryPhpFile('componenta-closure-callable-owner-', $original);

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, $replacement);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
})->with([
    'named function' => [
        '<?php function componentaOwnerA{suffix}(): \\Closure { return static fn (): int => 42; } return componentaOwnerA{suffix}();',
        '<?php function componentaOwnerB{suffix}(): \\Closure { return static fn (): int => 42; } return componentaOwnerB{suffix}();',
    ],
    'class method' => [
        '<?php class ComponentaOwner{suffix} { public static function makeA(): \\Closure { return static fn (): int => 42; } } return ComponentaOwner{suffix}::makeA();',
        '<?php class ComponentaOwner{suffix} { public static function makeB(): \\Closure { return static fn (): int => 42; } } return ComponentaOwner{suffix}::makeB();',
    ],
    'property hook' => [
        '<?php class ComponentaOwner{suffix} { public \\Closure $a { get => static fn (): int => 42; } } return (new ComponentaOwner{suffix}())->a;',
        '<?php class ComponentaOwner{suffix} { public \\Closure $b { get => static fn (): int => 42; } } return (new ComponentaOwner{suffix}())->b;',
    ],
]);

it('distinguishes multiple closures that share one source line', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-same-line-',
        '<?php declare(strict_types=1); return [static fn (int $value): int => $value + 1, '
        . 'static fn (string $value): string => strtoupper($value)];',
    );

    try {
        $closures = require $path;
        if (!is_array($closures) || !isset($closures[0], $closures[1])) {
            throw new LogicException('Expected two closure fixtures.');
        }

        $first = evaluateClosureExpression(VarExport::withDefaults()->export($closures[0]));
        $second = evaluateClosureExpression(VarExport::withDefaults()->export($closures[1]));

        expect($first(41))->toBe(42)
            ->and($second('componenta'))->toBe('COMPONENTA');
    } finally {
        unlink($path);
    }
});

it('selects an exact same-line closure when another candidate cannot be verified safely', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-same-line-defaults-',
        '<?php declare(strict_types=1); return ['
        . 'static fn ($value = new \\stdClass()): mixed => $value, '
        . 'static fn ($value = 1): mixed => $value'
        . '];',
    );

    try {
        $closures = require $path;
        if (!is_array($closures) || !isset($closures[1]) || !$closures[1] instanceof Closure) {
            throw new LogicException('Expected the exact same-line closure fixture.');
        }

        $restored = evaluateClosureExpression(VarExport::withDefaults()->export($closures[1]));

        expect($restored())->toBe(1);
    } finally {
        unlink($path);
    }
});

it('distinguishes outer and nested closures that share one source line', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-depth-',
        '<?php declare(strict_types=1); return static fn () => static fn () => 42;',
    );

    try {
        $outer = requireClosureFile($path);
        $inner = $outer();
        if (!$inner instanceof Closure) {
            throw new LogicException('Expected the outer fixture to return a Closure.');
        }

        $restoredOuter = evaluateClosureExpression(VarExport::withDefaults()->export($outer));
        $restoredInner = evaluateClosureExpression(VarExport::withDefaults()->export($inner));
        $nested = $restoredOuter();

        if (!$nested instanceof Closure) {
            throw new LogicException('Expected restored outer closure to return a Closure.');
        }

        expect($nested())->toBe(42)
            ->and($restoredInner())->toBe(42);
    } finally {
        unlink($path);
    }
});

it('rejects source whose reflected signature changed after closure creation', function (
    string $original,
    string $replacement,
): void {
    $path = createTemporaryPhpFile('componenta-closure-signature-', $original);

    try {
        $closure = requireClosureFile($path);
        overwriteFile($path, $replacement);

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'no longer matches');
    } finally {
        unlink($path);
    }
})->with([
    'parameter type' => [
        '<?php return static fn (int $value = 1): int => $value;',
        '<?php return static fn (string $value = "1"): int => (int) $value;',
    ],
    'parameter default' => [
        '<?php return static fn (int $value = 1): int => $value;',
        '<?php return static fn (int $value = 2): int => $value;',
    ],
    'nested signed-zero default' => [
        '<?php return static fn (array $value = [[-0.0]]): array => $value;',
        '<?php return static fn (array $value = [[0.0]]): array => $value;',
    ],
    'parameter reference mode' => [
        '<?php return static fn (int $value): int => $value;',
        '<?php return static fn (int &$value): int => $value;',
    ],
    'variadic parameter' => [
        '<?php return static fn (int $value): int => $value;',
        '<?php return static fn (int ...$value): int => $value[0];',
    ],
    'return type' => [
        '<?php return static fn (int $value): int => $value;',
        '<?php return static fn (int $value): string => (string) $value;',
    ],
]);

it('rejects a closure whose source file was removed after creation', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-closure-removed-source-',
        '<?php return static fn (): int => 42;',
    );
    $closure = requireClosureFile($path);
    unlink($path);

    expect(fn (): string => VarExport::withDefaults()->export($closure))
        ->toThrow(ExportException::class, 'source file');
});

it('uses a valid custom expression when freezing a safe capture', function (): void {
    $custom = new CallbackExporter(
        static fn (mixed $var): ?string => is_string($var) ? 'strtoupper("custom")' : null,
    );
    $captured = 'value';
    $closure = static fn (): string => $captured;
    $varExport = new VarExport(
        $custom,
        new ScalarExporter(),
        new ArrayExporter(),
        new EnumExporter(),
        new ClosureExporter(),
    );

    $restored = evaluateClosureExpression($varExport->export($closure));

    expect($restored())->toBe('CUSTOM');
});

it('propagates custom exporter failures raised while freezing captures', function (): void {
    $failure = new DomainException('capture exporter failed');
    $custom = new CallbackExporter(
        static function (mixed $var) use ($failure): ?string {
            if (is_string($var)) {
                throw $failure;
            }

            return null;
        },
    );
    $captured = 'value';
    $closure = static fn (): string => $captured;
    $varExport = new VarExport(
        $custom,
        new ScalarExporter(),
        new ArrayExporter(),
        new EnumExporter(),
        new ClosureExporter(),
    );

    $caught = null;
    try {
        $varExport->export($closure);
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
});

it('rejects an invalid custom expression used for a safe capture', function (string $expression): void {
    $custom = new CallbackExporter(
        static fn (mixed $var): ?string => is_string($var) ? $expression : null,
    );
    $captured = 'value';
    $closure = static fn (): string => $captured;
    $varExport = new VarExport(
        $custom,
        new ScalarExporter(),
        new ArrayExporter(),
        new EnumExporter(),
        new ClosureExporter(),
    );

    expect(fn (): string => $varExport->export($closure))
        ->toThrow(ExportException::class, 'invalid expression');
})->with([
    'empty expression' => [''],
    'syntax error' => ['not valid ???'],
    'statement injection' => ['42; phpinfo()'],
]);

it('rejects an invalid append in a modified parameter default with a library exception', function (): void {
    $path = createTemporaryPhpFile(
        'componenta-default-append-',
        '<?php return static fn (array $value = [1]): array => $value;',
    );

    try {
        $closure = requireClosureFile($path);
        overwriteFile(
            $path,
            '<?php return static fn (array $value = [' . PHP_INT_MAX . ' => 1, 2]): array => $value;',
        );

        expect(fn (): string => VarExport::withDefaults()->export($closure))
            ->toThrow(ExportException::class, 'parameter default');
    } finally {
        unlink($path);
    }
});
