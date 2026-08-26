<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\SourcePathPolicy;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Internal\ClosurePortabilityAnalyzer;
use Componenta\VarExport\Internal\DetachedNodeCloner;
use Componenta\VarExport\Internal\UseVariableValueNodeFactory;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Name\Relative;
use PhpParser\Node\Scalar\MagicConst\File;
use ReflectionFunction;
use RuntimeException;

enum InternalCoverageEnum
{
    case Ready;
}

function coverageSourceLocalFunction(): int
{
    return 1;
}

it('covers portability analyzer name-resolution and runtime-symbol branches', function (): void {
    $sourceBound = new ClosurePortabilityAnalyzer(
        ClosureExportPolicy::SourceBound,
        SourcePathPolicy::AbsoluteBuildPath,
        __FILE__,
        __LINE__,
        '{closure:' . __FILE__ . ':1}',
    );
    expect($sourceBound->enterNode(new File()))->toBeNull();

    $portable = new ClosurePortabilityAnalyzer(
        ClosureExportPolicy::PortableExpression,
        SourcePathPolicy::AbsoluteBuildPath,
        __FILE__,
        __LINE__,
    );

    expect($portable->enterNode(new FuncCall(new FullyQualified('strlen'))))->toBeNull();
    expect($portable->enterNode(new FuncCall(new Relative(['missing_function']))))->toBeNull();
    expect($portable->enterNode(new FuncCall(new Name('Componenta\\VarExport\\var_export'))))->toBeNull();
    expect($portable->enterNode(new FuncCall(new Name('definitely_missing_function'))))->toBeNull();

    expect(fn() => $portable->enterNode(new FuncCall(
        new FullyQualified(__NAMESPACE__ . '\\coverageSourceLocalFunction'),
    )))->toThrow(ClosureExportException::class, 'provider source file');

    expect($portable->enterNode(new ConstFetch(new Name('true'))))->toBeNull();
    expect($portable->enterNode(new ConstFetch(new FullyQualified('PHP_VERSION_ID'))))->toBeNull();
    expect($portable->enterNode(new ConstFetch(new Relative(['MISSING_CONSTANT']))))->toBeNull();

    $constant = 'COMPONENTA_VAR_EXPORT_COVERAGE_' . strtoupper(bin2hex(random_bytes(6)));
    define($constant, 42);
    expect(fn() => $portable->enterNode(new ConstFetch(new FullyQualified($constant))))
        ->toThrow(ClosureExportException::class, 'runtime user-defined constant');
});

it('deep-clones parser node attributes including nested attribute arrays', function (): void {
    $direct = new Node\Scalar\String_('direct');
    $nested = new Node\Scalar\String_('nested');
    $deep = new Node\Scalar\String_('deep');
    $node = new Node\Scalar\String_('root', [
        'direct' => $direct,
        'nested' => [$nested, ['deep' => $deep]],
        'scalar' => 42,
    ]);

    $clone = (new DetachedNodeCloner())->enterNode($node);
    $attributes = $clone->getAttributes();

    expect($clone)->not->toBe($node)
        ->and($attributes['direct'])->not->toBe($direct)
        ->and($attributes['nested'][0])->not->toBe($nested)
        ->and($attributes['nested'][1]['deep'])->not->toBe($deep)
        ->and($attributes['scalar'])->toBe(42);
});

it('covers capture literal generation and unsupported capture diagnostics', function (): void {
    expect(UseVariableValueNodeFactory::fromValue(null, 'v', 3))->toBeInstanceOf(Node\Expr\ConstFetch::class);
    expect(UseVariableValueNodeFactory::fromValue(true, 'v', 3))->toBeInstanceOf(Node\Expr\ConstFetch::class);
    expect(UseVariableValueNodeFactory::fromValue(false, 'v', 3))->toBeInstanceOf(Node\Expr\ConstFetch::class);
    expect(UseVariableValueNodeFactory::fromValue(1, 'v', 3))->toBeInstanceOf(Node\Scalar\Int_::class);
    expect(UseVariableValueNodeFactory::fromValue(1.5, 'v', 3))->toBeInstanceOf(Node\Scalar\Float_::class);
    expect(UseVariableValueNodeFactory::fromValue(INF, 'v', 3))->toBeInstanceOf(Node\Expr\ConstFetch::class);
    expect(UseVariableValueNodeFactory::fromValue(-INF, 'v', 3))->toBeInstanceOf(Node\Expr\UnaryMinus::class);
    expect(UseVariableValueNodeFactory::fromValue(NAN, 'v', 3))->toBeInstanceOf(Node\Expr\ConstFetch::class);
    expect(UseVariableValueNodeFactory::fromValue('text', 'v', 3))->toBeInstanceOf(Node\Scalar\String_::class);
    expect(UseVariableValueNodeFactory::fromValue(InternalCoverageEnum::Ready, 'v', 3))->toBeInstanceOf(Node\Expr\ClassConstFetch::class);
    expect(UseVariableValueNodeFactory::fromValue(['key' => [1]], 'v', 3))->toBeInstanceOf(Node\Expr\Array_::class);

    expect(fn() => UseVariableValueNodeFactory::fromValue([[[1]]], 'v', 1))
        ->toThrow(ClosureExportException::class, 'exceeds maxDepth');

    $shared = 1;
    $referenced = ['key' => &$shared];
    expect(fn() => UseVariableValueNodeFactory::fromValue($referenced, 'v', 3))
        ->toThrow(ClosureExportException::class, 'array reference');

    expect(fn() => UseVariableValueNodeFactory::fromValue(static fn(): int => 1, 'v', 3))
        ->toThrow(ClosureExportException::class, 'nested Closure');
    expect(fn() => UseVariableValueNodeFactory::fromValue(new \stdClass(), 'v', 3))
        ->toThrow(ClosureExportException::class, 'object (stdClass)');

    $resource = fopen('php://memory', 'rb');
    expect(fn() => UseVariableValueNodeFactory::fromValue($resource, 'v', 3))
        ->toThrow(ClosureExportException::class, 'resource (stream)');
    fclose($resource);
    expect(fn() => UseVariableValueNodeFactory::fromValue($resource, 'v', 3))
        ->toThrow(ClosureExportException::class, 'resource (closed)');
});

it('covers structured export exception factories and source metadata', function (): void {
    $previous = new RuntimeException('previous');
    $exception = new ExportException('message', ['a' => 1], '/tmp/source.php', 123, $previous);
    expect($exception->getFile())->toBe('/tmp/source.php')
        ->and($exception->getLine())->toBe(123)
        ->and($exception->context)->toBe(['a' => 1])
        ->and($exception->getPrevious())->toBe($previous);

    expect(ExportException::unsupportedType(static fn(): int => 1)->getMessage())->toContain('Closure');
    expect(ExportException::unexportableObject(new \stdClass())->context['class'])->toBe(\stdClass::class);

    $resource = fopen('php://memory', 'rb');
    expect(ExportException::resourceNotExportable($resource)->getMessage())->toContain('stream');
    fclose($resource);
    expect(ExportException::resourceNotExportable($resource)->getMessage())->toContain('closed resource');
    expect(ExportException::resourceNotExportable(new \stdClass())->getMessage())->toContain('stdClass');
    expect(ExportException::objectCycle(\stdClass::class, 4)->context['depth'])->toBe(4);
    expect(ExportException::formatKeyPath([]))->toBe('root');
    expect(ExportException::formatKeyPath(['a', 2]))->toBe("\$array['a'][2]");

    expect(ArrayExportException::maxDepthExceeded(1, 2, ['a'])->getMessage())->toContain("\$array['a']");
    expect(ArrayExportException::unexportableElement('a', 'resource', 2, ['a'], $previous)->getPrevious())->toBe($previous);
    expect(ArrayExportException::referencedElement('a', ['a'])->context['key'])->toBe('a');
    expect(ArrayExportException::closureExporterMissing('a', 1, ['a'])->context['depth'])->toBe(1);
    expect(ArrayExportException::invalidDepth(-1)->context['depth'])->toBe(-1);
});

it('covers closure exception factories including capture paths and reflection metadata', function (): void {
    $closure = static function (int $value = 1): int {
        return $value;
    };
    $reflection = new ReflectionFunction($closure);
    $parameter = $reflection->getParameters()[0];
    $previous = new RuntimeException('failure');

    expect(ClosureExportException::namedCallable(new ReflectionFunction('strlen'))->getMessage())->toContain('strlen');
    expect(ClosureExportException::unverifiableParameterDefault($parameter, $previous)->getPrevious())->toBe($previous);
    expect(ClosureExportException::sourceNotFound('unknown')->context['filename'])->toBe('unknown');
    expect(ClosureExportException::sourceNotFound('/tmp/missing.php')->getFile())->toBe('/tmp/missing.php');
    expect(ClosureExportException::sourceUnreadable('/tmp/unreadable.php')->getMessage())->toContain('Cannot read');
    expect(ClosureExportException::sourceTooLarge('/tmp/large.php', 100, 10)->context['bytes'])->toBe(100);
    expect(ClosureExportException::parsingFailed('/tmp/bad.php', 'syntax', $previous)->getPrevious())->toBe($previous);
    expect(ClosureExportException::nodeNotFound(5, '/tmp/a.php')->getLine())->toBe(5);
    expect(ClosureExportException::staleSource($reflection)->getMessage())->toContain('no longer matches');
    expect(ClosureExportException::ambiguousLocation(5, 2, '/tmp/a.php')->context['count'])->toBe(2);
    expect(ClosureExportException::nonPortableScope($reflection, 'scope changed')->context['reason'])->toBe('scope changed');
    expect(ClosureExportException::nestingDepthExceeded(1, 2)->context['depth'])->toBe(2);
    expect(ClosureExportException::nonPortableExpression('reason')->context['reason'])->toBe('reason');
    expect(ClosureExportException::cannotInlineUseVariables(['x' => 'by reference'])->getMessage())->toContain('$x');
    expect(ClosureExportException::captureValueNotExportable('x', 'object', [0, 'a'])->getMessage())->toContain("\$capture[0]['a']");
    expect(ClosureExportException::captureValueNotExportable('x', 'object', [])->getMessage())->toContain('capture root');
    expect(ClosureExportException::captureDepthExceeded('x', 1, 2, ['a'])->context['max_depth'])->toBe(1);
    expect(ClosureExportException::internalFailure($reflection, $previous)->getPrevious())->toBe($previous);

    $bound = (function (): object {
        return $this;
    })->bindTo(new \stdClass());
    expect(ClosureExportException::boundThis(new ReflectionFunction($bound))->context['bound_class'])->toBe(\stdClass::class);
});
