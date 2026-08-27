<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExporter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ContractSupportedValueObject
{
    public function __construct(public int $n, public string $label)
    {
    }
}

final readonly class ContractNestedValueObject
{
    public function __construct(public ContractSupportedValueObject $child)
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

function readonlyExporter(?ExportConfig $config = null): VarExporter
{
    return new VarExporter(($config ?? new ExportConfig())->withGenericReadonlyObjects());
}

it('keeps generic readonly-object export disabled by default', function (): void {
    expect(fn() => (new VarExporter())->export(new ContractSupportedValueObject(42, 'ok')))
        ->toThrow(ExportException::class, 'disabled');
});

it('rejects state not represented by public promoted properties', function (): void {
    expect(fn() => readonlyExporter()->export(new ContractPrivateStateValueObject('hidden')))
        ->toThrow(ExportException::class, 'must be public, concrete, and hook-free');
});

it('round-trips explicitly enabled constructor value objects', function (): void {
    $value = new ContractSupportedValueObject(42, 'ok');
    $restored = eval('return ' . readonlyExporter()->export($value) . ';');

    expect($restored)->toEqual($value);
});

it('round-trips stateless readonly value objects', function (): void {
    $restored = eval('return ' . readonlyExporter()->export(new ContractEmptyReadonly()) . ';');

    expect($restored)->toBeInstanceOf(ContractEmptyReadonly::class);
});

it('keeps hydrated constructor replay an explicit opt-in risk', function (): void {
    $reflection = new ReflectionClass(ContractHydratedValueObject::class);
    $object = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('n')->setValue($object, -1);

    expect(fn() => (new VarExporter())->export($object))
        ->toThrow(ExportException::class, 'disabled');

    $code = readonlyExporter()->export($object);
    expect(fn() => eval('return ' . $code . ';'))->toThrow(InvalidArgumentException::class);
});

it('rejects anonymous readonly classes', function (): void {
    $object = new readonly class (42) {
        public function __construct(public int $n)
        {
        }
    };

    expect(fn() => readonlyExporter()->export($object))
        ->toThrow(ExportException::class, 'Anonymous readonly class');
});

it('rejects mutable classes from generic readonly reconstruction', function (): void {
    $mutable = new class {
        public int $n = 1;
    };

    expect(fn() => readonlyExporter()->export($mutable))
        ->toThrow(ExportException::class, 'Cannot export object');
});

it('rejects readonly state without a constructor', function (): void {
    $object = (new ReflectionClass(ContractNoConstructorState::class))->newInstanceWithoutConstructor();

    expect(fn() => readonlyExporter()->export($object))
        ->toThrow(ExportException::class, 'state but no constructor');
});

it('rejects a non-public reconstruction constructor', function (): void {
    expect(fn() => readonlyExporter()->export(ContractPrivateConstructor::make(1)))
        ->toThrow(ExportException::class, 'must be public');
});

it('rejects variadic constructor parameters', function (): void {
    expect(fn() => readonlyExporter()->export(new ContractVariadicConstructor()))
        ->toThrow(ExportException::class, 'variadic or passed by reference');
});

it('rejects by-reference constructor parameters', function (): void {
    $value = 1;

    expect(fn() => readonlyExporter()->export(new ContractByReferenceConstructor($value)))
        ->toThrow(ExportException::class, 'variadic or passed by reference');
});

it('rejects non-promoted constructor parameters', function (): void {
    expect(fn() => readonlyExporter()->export(new ContractNonPromotedConstructor(1)))
        ->toThrow(ExportException::class, 'must be a promoted property');
});

it('rejects non-public promoted state', function (): void {
    expect(fn() => readonlyExporter()->export(new ContractPrivatePromoted(1)))
        ->toThrow(ExportException::class, 'must be public, concrete, and hook-free');
});

it('rejects an uninitialized promoted property', function (): void {
    $object = (new ReflectionClass(ContractSupportedValueObject::class))->newInstanceWithoutConstructor();

    expect(fn() => readonlyExporter()->export($object))
        ->toThrow(ExportException::class, 'is not initialized');
});

it('rejects instance state outside constructor parameters', function (): void {
    expect(fn() => readonlyExporter()->export(new ContractExtraState(1)))
        ->toThrow(ExportException::class, 'not represented by its constructor');
});

it('rejects __unserialize hydration as a generic reconstruction contract', function (): void {
    expect(fn() => readonlyExporter()->export(new ContractUnserializeValue(1)))
        ->toThrow(ExportException::class, 'defines __unserialize');
});

it('applies maxDepth to nested readonly object state', function (): void {
    $exporter = readonlyExporter(new ExportConfig(maxDepth: 1));
    $value = new ContractNestedValueObject(new ContractSupportedValueObject(1, 'leaf'));

    expect(fn() => $exporter->export($value))
        ->toThrow(ExportException::class, 'Maximum nesting depth');
});
