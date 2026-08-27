<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ObjectExporter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ContractSupportedValueObject
{
    public function __construct(public int $n, public string $label)
    {
    }
}

final readonly class ContractPrivateStateValueObject
{
    public function __construct(private string $secret)
    {
    }
}

final readonly class ContractHydratedValueObject
{
    public function __construct(public int $n)
    {
        if ($n < 0) {
            throw new InvalidArgumentException('negative state is not constructor-valid');
        }
    }
}

final readonly class ContractEmptyReadonly
{
}

final readonly class ContractNoConstructorState
{
    public int $value;
}

final readonly class ContractPrivateConstructor
{
    private function __construct(public int $value)
    {
    }

    public static function make(int $value): self
    {
        return new self($value);
    }
}

final readonly class ContractVariadicConstructor
{
    public function __construct(int ...$values)
    {
    }
}

final readonly class ContractByReferenceConstructor
{
    public function __construct(int &$value)
    {
    }
}

final readonly class ContractNonPromotedConstructor
{
    public function __construct(int $value)
    {
    }
}

final readonly class ContractPrivatePromoted
{
    public function __construct(private int $value)
    {
    }
}

final readonly class ContractExtraState
{
    public string $extra;

    public function __construct(public int $value)
    {
        $this->extra = 'extra';
    }
}

final readonly class ContractUnserializeValue
{
    public function __construct(public int $value)
    {
    }

    public function __unserialize(array $data): void
    {
    }
}

it('keeps generic readonly-object export disabled by default', function (): void {
    expect((new ObjectExporter())->supports(new ContractSupportedValueObject(42, 'ok')))->toBeFalse();
});

it('reports unsupported when state is not represented by public promoted properties', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect($exporter->supports(new ContractPrivateStateValueObject('hidden')))->toBeFalse();
});

it('round-trips explicitly enabled constructor value objects', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    $value = new ContractSupportedValueObject(42, 'ok');
    $restored = eval('return ' . $exporter->export($value) . ';');

    expect($exporter->supports($value))->toBeTrue()
        ->and($restored)->toEqual($value);
});

it('round-trips stateless readonly value objects', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    $restored = eval('return ' . $exporter->export(new ContractEmptyReadonly()) . ';');

    expect($restored)->toBeInstanceOf(ContractEmptyReadonly::class);
});

it('makes hydrated constructor replay an explicit caller risk rather than default support', function (): void {
    $reflection = new ReflectionClass(ContractHydratedValueObject::class);
    $object = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('n')->setValue($object, -1);

    expect((new ObjectExporter())->supports($object))->toBeFalse();

    $explicit = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());
    expect($explicit->supports($object))->toBeTrue();

    $code = $explicit->export($object);
    expect(fn() => eval('return ' . $code . ';'))->toThrow(InvalidArgumentException::class);
});

it('rejects anonymous readonly classes because generated PHP cannot name them', function (): void {
    $object = new readonly class (42) {
        public function __construct(public int $n)
        {
        }
    };
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect($exporter->supports($object))->toBeFalse();
});

it('rejects mutable classes from generic readonly reconstruction', function (): void {
    $mutable = new class {
        public int $n = 1;
    };
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect($exporter->supports($mutable))->toBeFalse();
});

it('rejects readonly state without a constructor', function (): void {
    $object = (new ReflectionClass(ContractNoConstructorState::class))->newInstanceWithoutConstructor();
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export($object))
        ->toThrow(ExportException::class, 'state but no constructor');
});

it('rejects a non-public reconstruction constructor', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(ContractPrivateConstructor::make(1)))
        ->toThrow(ExportException::class, 'must be public');
});

it('rejects variadic constructor parameters', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(new ContractVariadicConstructor()))
        ->toThrow(ExportException::class, 'variadic or passed by reference');
});

it('rejects by-reference constructor parameters', function (): void {
    $value = 1;
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(new ContractByReferenceConstructor($value)))
        ->toThrow(ExportException::class, 'variadic or passed by reference');
});

it('rejects non-promoted constructor parameters', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(new ContractNonPromotedConstructor(1)))
        ->toThrow(ExportException::class, 'must be a promoted property');
});

it('rejects non-public promoted state', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(new ContractPrivatePromoted(1)))
        ->toThrow(ExportException::class, 'must be public, concrete, and hook-free');
});

it('rejects an uninitialized promoted property', function (): void {
    $object = (new ReflectionClass(ContractSupportedValueObject::class))->newInstanceWithoutConstructor();
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export($object))
        ->toThrow(ExportException::class, 'is not initialized');
});

it('rejects instance state that is not represented by constructor parameters', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(new ContractExtraState(1)))
        ->toThrow(ExportException::class, 'not represented by its constructor');
});

it('rejects __unserialize hydration as a generic reconstruction contract', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects());

    expect(fn() => $exporter->export(new ContractUnserializeValue(1)))
        ->toThrow(ExportException::class, 'defines __unserialize');
});

it('throws a structured error when nesting exceeds maxDepth', function (): void {
    $exporter = new ObjectExporter((new ExportConfig(maxDepth: 2))->withGenericReadonlyObjects());
    $leaf = new ContractSupportedValueObject(1, 'leaf');

    expect(fn() => $exporter->exportWithDepth($leaf, 10))
        ->toThrow(ExportException::class, 'Maximum nesting depth');
});
