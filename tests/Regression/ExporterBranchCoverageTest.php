<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Closure;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;
use Componenta\VarExport\VarExporter;
use ReflectionClass;
use RuntimeException;

class CoverageLegacyClosureExporter implements ClosureExporterInterface
{
    /** @var list<int> */
    public array $depths = [];

    public function export(Closure $closure): string
    {
        return 'static fn (): int => 7';
    }

    public function exportWithDepth(Closure $closure, int $depth): string
    {
        $this->depths[] = $depth;

        return $this->export($closure);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new static();
    }
}

final class CoverageContextualClosureExporter extends CoverageLegacyClosureExporter implements ContextualClosureExporterInterface
{
    /** @var list<string> */
    public array $locations = [];

    public function exportWithContext(Closure $closure, ExportContext $context): string
    {
        $this->locations[] = $context->location();

        return $this->export($closure);
    }
}

class CoverageLegacyObjectExporter implements ObjectExporterInterface
{
    /** @var list<int> */
    public array $depths = [];

    public function export(object $object): string
    {
        return 'new \\stdClass()';
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        $this->depths[] = $depth;

        return $this->export($object);
    }

    public function supports(object $object): bool
    {
        return true;
    }

    public function withConfig(ExportConfig $config): static
    {
        return new static();
    }
}

final class CoverageThrowingObjectExporter extends CoverageLegacyObjectExporter
{
    public function exportWithDepth(object $object, int $depth): string
    {
        throw new RuntimeException('custom object exporter failed');
    }
}

final class CoverageArrayExporter implements ArrayExporterInterface
{
    /** @var list<array{int, string}> */
    public array $calls = [];

    public function export(array $array): string
    {
        return '[99]';
    }

    public function exportAtDepth(array $array, int $depth, string $baseIndent): string
    {
        $this->calls[] = [$depth, $baseIndent];

        return $this->export($array);
    }

    public function withConfig(ExportConfig $config): static
    {
        return $this;
    }
}

final class CoverageValueExporter implements ContextualValueExporterInterface
{
    /** @var list<string> */
    public array $locations = [];

    public function exportValue(mixed $value, ExportContext $context): string
    {
        $this->locations[] = $context->location();

        return var_export($value, true);
    }
}

enum CoverageEnum
{
    case Ready;
}

final readonly class CoverageEmptyReadonly
{
}

final readonly class CoverageSingleValue
{
    public function __construct(public mixed $value)
    {
    }
}

final readonly class CoveragePairValue
{
    public function __construct(public int $first, public int $second)
    {
    }
}

final readonly class CoverageNoConstructorState
{
    public int $value;
}

final readonly class CoveragePrivateConstructor
{
    private function __construct(public int $value)
    {
    }

    public static function make(int $value): self
    {
        return new self($value);
    }
}

final readonly class CoverageVariadicConstructor
{
    public function __construct(int ...$values)
    {
    }
}

final readonly class CoverageByReferenceConstructor
{
    public function __construct(int &$value)
    {
    }
}

final readonly class CoverageNonPromotedConstructor
{
    public function __construct(int $value)
    {
    }
}

final readonly class CoveragePrivatePromoted
{
    public function __construct(private int $value)
    {
    }
}

final readonly class CoverageExtraState
{
    public string $extra;

    public function __construct(public int $value)
    {
        $this->extra = 'extra';
    }
}

final readonly class CoverageUnserializeValue
{
    public function __construct(public int $value)
    {
    }

    public function __unserialize(array $data): void
    {
    }
}

it('covers ArrayExporter scalar, legacy, contextual and failure branches', function (): void {
    $legacyClosure = new CoverageLegacyClosureExporter();
    $legacyObject = new CoverageLegacyObjectExporter();
    $exporter = new ArrayExporter(
        closureExporter: $legacyClosure,
        objectExporter: $legacyObject,
    );

    $closureCode = $exporter->export(['closure' => static fn(): int => 1]);
    expect($closureCode)->toContain('static fn');
    expect($legacyClosure->depths)->toBe([1]);

    $objectCode = $exporter->export(['object' => new \stdClass()]);
    expect($objectCode)->toContain('new \\stdClass()');
    expect($legacyObject->depths)->toBe([1]);

    $contextualClosure = new CoverageContextualClosureExporter();
    $contextualCode = (new ArrayExporter(closureExporter: $contextualClosure))
        ->export(['closure' => static fn(): int => 2]);
    expect($contextualCode)->toContain('static fn');
    expect($contextualClosure->locations)->toBe(["\$value['closure']"]);

    $enumCode = (new ArrayExporter(objectExporter: new ObjectExporter()))
        ->export(['enum' => CoverageEnum::Ready]);
    expect($enumCode)->toContain('CoverageEnum::Ready');

    $atDepth = (new ArrayExporter(ExportConfig::pretty()->withIndent('  ')))
        ->exportAtDepth(['key' => true], 1, '  ');
    expect($atDepth)->toContain("\n    'key' => true");

    $resource = fopen('php://memory', 'rb');
    expect(fn() => (new ArrayExporter())->export(['resource' => $resource]))
        ->toThrow(ArrayExportException::class, 'resource');
    fclose($resource);

    expect(fn() => (new ArrayExporter())->export(['closed' => $resource]))
        ->toThrow(ArrayExportException::class, 'resource (closed)');

    expect(fn() => (new ArrayExporter())->export(['object' => new \stdClass()]))
        ->toThrow(ArrayExportException::class, \stdClass::class);

    try {
        (new ArrayExporter(objectExporter: new CoverageThrowingObjectExporter()))
            ->export(['object' => new \stdClass()]);
        test()->fail('Expected wrapped object-export failure.');
    } catch (ArrayExportException $e) {
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

it('covers ArrayExporter contextual value dispatch and direct depth guard', function (): void {
    $values = new CoverageValueExporter();
    $exporter = new ArrayExporter(valueExporter: $values);

    expect($exporter->export(['a' => 1, 'b' => false]))->toBe("['a' => 1, 'b' => false]");
    expect($values->locations)->toBe(["\$value['a']", "\$value['b']"]);

    expect(fn() => $exporter->exportWithContext(
        [1],
        new ExportContext(5, path: ['deep']),
    ))->toThrow(ArrayExportException::class, 'Maximum nesting depth');
});

it('covers ExportContext construction, propagation and location formatting', function (): void {
    $root = ExportContext::root();
    expect($root->location())->toBe('root');

    $child = $root->child('items', '  ')->child(3, '    ');
    expect($child->depth)->toBe(2)
        ->and($child->baseIndent)->toBe('    ')
        ->and($child->location())->toBe("\$value['items'][3]")
        ->and($child->activeObjects)->toBe($root->activeObjects);

    $reindented = $child->withBaseIndent("\t");
    expect($reindented->baseIndent)->toBe("\t")
        ->and($reindented->path)->toBe($child->path)
        ->and($reindented->activeObjects)->toBe($child->activeObjects);

    expect(fn() => new ExportContext(-1))->toThrow(\InvalidArgumentException::class, 'non-negative');
    expect(fn() => new ExportContext(baseIndent: " \n"))->toThrow(\InvalidArgumentException::class, 'spaces and tabs');
});

it('covers ObjectExporter special values and constructor reconstruction guards', function (): void {
    $config = (new ExportConfig())->withGenericReadonlyObjects();
    $exporter = new ObjectExporter($config);

    expect($exporter->export(CoverageEnum::Ready))->toContain('CoverageEnum::Ready');
    expect($exporter->export(new CoverageEmptyReadonly()))->toContain('new \\' . CoverageEmptyReadonly::class . '()');

    expect(fn() => $exporter->export(new \DateTimeImmutable()))
        ->toThrow(ExportException::class, 'Internal/extension class');
    expect(fn() => $exporter->export(new CoverageNoConstructorState()))
        ->toThrow(ExportException::class, 'state but no constructor');
    expect(fn() => $exporter->export(CoveragePrivateConstructor::make(1)))
        ->toThrow(ExportException::class, 'must be public');
    expect(fn() => $exporter->export(new CoverageVariadicConstructor()))
        ->toThrow(ExportException::class, 'variadic or passed by reference');

    $byRefValue = 1;
    expect(fn() => $exporter->export(new CoverageByReferenceConstructor($byRefValue)))
        ->toThrow(ExportException::class, 'variadic or passed by reference');
    expect(fn() => $exporter->export(new CoverageNonPromotedConstructor(1)))
        ->toThrow(ExportException::class, 'must be a promoted property');
    expect(fn() => $exporter->export(new CoveragePrivatePromoted(1)))
        ->toThrow(ExportException::class, 'must be public, concrete, and hook-free');

    $uninitialized = (new ReflectionClass(CoverageSingleValue::class))->newInstanceWithoutConstructor();
    expect(fn() => $exporter->export($uninitialized))
        ->toThrow(ExportException::class, 'is not initialized');
    expect(fn() => $exporter->export(new CoverageExtraState(1)))
        ->toThrow(ExportException::class, 'not represented by its constructor');
    expect(fn() => $exporter->export(new CoverageUnserializeValue(1)))
        ->toThrow(ExportException::class, 'defines __unserialize');
});

it('covers ObjectExporter closure, resource, provider and formatting branches', function (): void {
    $config = (new ExportConfig())->withGenericReadonlyObjects();

    expect(fn() => (new ObjectExporter())->export(static fn(): int => 1))
        ->toThrow(ExportException::class, 'ClosureExporterInterface');

    $legacyClosure = new CoverageLegacyClosureExporter();
    $legacy = new ObjectExporter($config, closureExporter: $legacyClosure);
    expect($legacy->exportWithDepth(static fn(): int => 1, 2))->toContain('static fn');
    expect($legacyClosure->depths)->toBe([2]);

    expect(fn() => $legacy->exportWithDepth(new CoverageSingleValue(1), -1))
        ->toThrow(ExportException::class, 'non-negative');

    $resource = fopen('php://memory', 'rb');
    expect(fn() => $legacy->export(new CoverageSingleValue($resource)))
        ->toThrow(ExportException::class, 'Resource of type');
    fclose($resource);
    expect(fn() => $legacy->export(new CoverageSingleValue($resource)))
        ->toThrow(ExportException::class, 'Cannot export value of type');

    $invalidProvider = new ObjectExporter(
        $config,
        arrayExporterProvider: static fn(): object => new \stdClass(),
    );
    expect(fn() => $invalidProvider->export(new CoverageSingleValue([1])))
        ->toThrow(ExportException::class, 'array provider must return');

    $customArray = new CoverageArrayExporter();
    $provider = new ObjectExporter(
        $config,
        arrayExporterProvider: static fn(): ArrayExporterInterface => $customArray,
    );
    expect($provider->export(new CoverageSingleValue([1, 2])))->toContain('[99]');
    expect($customArray->calls)->toBe([[1, '    ']]);

    $pretty = new ObjectExporter(ExportConfig::pretty()->withGenericReadonlyObjects());
    expect($pretty->export(new CoveragePairValue(1, 2)))->toContain("\n");
    expect($pretty->export(new CoverageSingleValue([1, 2])))->toContain("\n");

    $valueExporter = new CoverageValueExporter();
    $customValues = (new ObjectExporter($config))->withValueExporter($valueExporter);
    expect($customValues->export(new CoverageSingleValue('x')))->toContain("'x'");
    expect($valueExporter->locations)->toBe(["\$value['value']"]);
});

it('covers VarExporter getters, depth and unsupported values', function (): void {
    $exporter = new VarExporter(new ExportConfig(maxDepth: 1));

    expect($exporter->getArrayExporter())->toBeInstanceOf(ArrayExporterInterface::class)
        ->and($exporter->getClosureExporter())->toBeInstanceOf(ContextualClosureExporterInterface::class)
        ->and($exporter->getObjectExporter())->toBeInstanceOf(ObjectExporterInterface::class);

    expect(fn() => $exporter->export([[1]]))
        ->toThrow(ExportException::class, 'Maximum nesting depth');

    $resource = fopen('php://memory', 'rb');
    expect(fn() => $exporter->export($resource))->toThrow(ExportException::class, 'Resource of type');
    fclose($resource);
    expect(fn() => $exporter->export($resource))->toThrow(ExportException::class, 'Cannot export value of type');

    $pretty = $exporter->withConfig(ExportConfig::pretty());
    expect($pretty->getConfig()->isPretty())->toBeTrue();
});
