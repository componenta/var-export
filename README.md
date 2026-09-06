# Componenta VarExport

Componenta VarExport turns supported PHP values into deterministic, executable PHP expressions. It is designed for generated configuration and dependency-injection files: the library returns an expression only and never writes files or adds a PHP tag, `return`, or a terminating semicolon.

Requires PHP 8.4 or later.

## Installation

```bash
composer require componenta/var-export:^2.0@dev
```

## Quick start

```php
use Componenta\VarExport\VarExport;

$exporter = VarExport::withDefaults();

$expression = $exporter->export([
    'debug' => false,
    'ports' => [80, 443],
]);
```

The result is ready to embed in generated PHP:

```php
[
    "debug" => false,
    "ports" => [
        80,
        443,
    ],
]
```

## Public API

```php
namespace Componenta\VarExport;

interface VarExportInterface
{
    public function export(mixed $var): ?string;
}

interface VarExportAwareInterface
{
    public function withVarExport(VarExport $varExport): static;
}

final class VarExport
{
    public function __construct(VarExportInterface ...$exporters);
    public static function withDefaults(): self;
    public function export(mixed $var): string;
}
```

`VarExport` asks exporters in constructor order. An exporter returns `null` when it does not support a value; every string, including an empty string, completes the chain.

`withDefaults()` installs these public exporters in order:

1. `ScalarExporter`
2. `ArrayExporter`
3. `EnumExporter`
4. `ClosureExporter`

## Supported values

- `null`, booleans, integers, floats, and strings;
- `INF`, `-INF`, `NAN`, signed zero, control characters, and binary string bytes;
- enum cases as fully qualified `\ClassName::Case` expressions;
- ordered arrays using four-space indentation and trailing commas;
- source-backed portable closures with safe scalar, enum, and array captures inlined into the expression.

Default exporters reject arbitrary objects and resources. Config and DI integrations can add their own exporters before the defaults:

```php
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\VarExport;

$exporter = new VarExport(
    new ConfigValueExporter(),
    new ScalarExporter(),
    new ArrayExporter(),
    new EnumExporter(),
    new ClosureExporter(),
);
```

Composite exporters implement both `VarExportInterface` and `VarExportAwareInterface`. Their `withVarExport()` method must return a bound copy. The orchestrator replaces the original exporter with that copy, so exporter instances remain reusable.

`ArrayExporter` and `ClosureExporter` are composite exporters. When used directly, bind them first:

```php
$arrayExporter = (new ArrayExporter())
    ->withVarExport(VarExport::withDefaults());

$expression = $arrayExporter->export(['answer' => 42]);
```

## Closure portability

`ClosureExporter` reads the closure's source, verifies the reflected source location, resolves portable global functions and constants, freezes safe captures, and prints a canonical expression.

PHP automatic globals (`$GLOBALS`, `$_SERVER`, `$_ENV`, and the other superglobals) remain runtime lookups. They are not treated as captures and are not frozen into the generated expression.

The generated expression records whether the provider source uses `strict_types`. Loading it from a file compiled under the opposite mode throws `ExportException` instead of silently changing closure behavior.

It rejects behavior that cannot be reproduced safely in a generated file, including:

- captures by reference and references inside captured arrays;
- capture values deeper than 64 traversal levels;
- `$this`, anonymous or incompatible class scope, and static local state;
- captured objects, resources, and closures;
- named callables;
- `eval`, include/require, PHP mode switches, external `declare` directives other than `strict_types`, magic constants, nested named declarations, unresolved unqualified namespace-fallback symbols, runtime user constants, and functions defined only in the provider source file;
- missing, structurally changed, ambiguous, or invalid source.

The provider source must not be modified after the runtime closure is created. PHP Reflection does not expose closure body opcodes, so a body-only edit that preserves the reflected location, signature, and captures cannot be detected safely without executing user code.

## Errors

All library exceptions implement `Componenta\VarExport\Exception\ExceptionInterface`.

- `ExportException` means an exporter recognized the value but could not produce a safe expression.
- `UnsupportedValueException` means every exporter returned `null`.

Exceptions thrown by custom exporters are not intercepted.

## Development

```bash
composer validate --strict
composer cs-check
composer phpstan
composer test
composer test-coverage
composer mutation
```

The supported test matrix is PHP 8.4 and PHP 8.5.
