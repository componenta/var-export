# Componenta VarExport

[![CI](https://github.com/componenta/var-export/actions/workflows/ci.yml/badge.svg)](https://github.com/componenta/var-export/actions/workflows/ci.yml)

Generate deterministic executable PHP expressions for values the library can reproduce safely.

The stable entry points are `Export`, `VarExporterInterface`, `VarExporter`, and `ExportConfig`. Lower-level contextual array, closure, object, and source-cache contracts are available for advanced composition.

[Русская версия](README.ru.md)

## Requirements

- PHP 8.4+
- `nikic/php-parser` 5.8+

```bash
composer require componenta/var-export
```

## Value model

VarExport uses **value semantics**. It supports:

- `null`, booleans, integers, floats, and arbitrary byte strings;
- arrays without PHP references;
- anonymous source closures and arrow functions with a readable source file;
- enum cases;
- explicitly enabled, strict user-defined readonly constructor value objects;
- class-specific object strategies supplied by consumers.

It deliberately rejects or does not preserve:

- resources;
- array references and recursive reference arrays;
- repeated object identity (`===`) in the generic value model;
- mutable objects;
- anonymous/internal generic readonly objects;
- closures bound to an object through `$this`;
- named-callable closures created with `Closure::fromCallable()`;
- closure static local variables;
- late-static-binding state where Reflection reports different closure-scope and called classes;
- nested named-function or class-like declarations inside an exported closure;
- closures created by `eval()` or without readable source.

When `sortKeys` is enabled, associative-array iteration order is intentionally canonicalized and is therefore not an order-preserving round trip.

## Quick start

```php
use Componenta\VarExport\Export;

$code = Export::var([
    'host' => 'localhost',
    'port' => 5432,
    'ratio' => 0.10000000000000002,
]);

$restored = eval("return {$code};");
```

For an expression followed by a terminating semicolon use the explicit statement API:

```php
$statement = Export::statement(['env' => 'prod']);
// ['env' => 'prod'];
```

`statement()` / `VarExporter::exportStatement()` do **not** add `<?php` or `return`; VarExport is an expression exporter, not a complete PHP-file writer.

## Primitive representation

Primitive source uses PHP-safe representations. Finite floats (including signed zero) round-trip bit-exactly independently of the INI `precision` setting. `PHP_INT_MIN` remains an integer expression, and NUL/control/binary string bytes round-trip without octal ambiguity.

Non-finite floats are emitted as namespace-safe `\INF`, `-\INF`, and `\NAN`. `NaN` remains `NaN`, but NaN payload/signaling-bit identity is not part of the value contract.

## Configuration

```php
use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Config\SourcePathPolicy;

$config = new ExportConfig(
    mode: FormatterMode::Pretty,
    indent: '    ',       // one or more spaces, or exactly one tab
    maxDepth: 64,
    sortKeys: false,
    trailingComma: true,
    closureUseMode: ClosureUseMode::Preserve,
    allowGenericReadonlyObjects: false,
    closureExportPolicy: ClosureExportPolicy::SourceBound,
    sourcePathPolicy: SourcePathPolicy::AbsoluteBuildPath,
);
```

`ExportConfig` is immutable. `maxDepth` is counted from semantic root depth `0` and applies uniformly to every nested value, including arrays, object constructor arguments, closures, and Inline capture values.

With `sortKeys: true`, integer keys are ordered numerically before string keys; string keys use bytewise `strcmp()` ordering. String keys that remain strings under PHP array-key semantics are preserved as strings.

## Closure captures

### `ClosureUseMode::Preserve` — default

`Preserve` keeps lexical captures as variables. The generated expression is not self-contained: captured variables must exist where the expression is evaluated.

By-reference captures remain references in `Preserve` mode. By-value captures retain normal PHP copy semantics; writes inside one invocation do not become persistent closure state unless PHP itself provides such state through a reference or static local (static locals are rejected by VarExport).

### `ClosureUseMode::Inline`

`Inline` freezes supported capture **values** into a static creator expression:

```php
$config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
```

Supported Inline capture values are:

- `null`, booleans, integers, floats, and strings;
- enum cases;
- nested arrays of supported values without PHP references.

Object instances, resources, nested `Closure` objects, and by-reference captures are rejected instead of silently changing identity/reference semantics. Inline captures use the same global `maxDepth` boundary.

For non-static closures whose original `$this` is `null`, VarExport creates the closure behind a static creator boundary before restoring any class scope. This prevents an exported expression evaluated inside an object method from accidentally acquiring that ambient object's `$this`.

## Closure source, namespaces, and relocation

Closure export is source-based. The current source file is parsed with `nikic/php-parser`; names and magic constants are then transformed only when VarExport can preserve the represented semantics.

### `ClosureExportPolicy::SourceBound`

`SourceBound` preserves source semantics that can be frozen into a standalone expression, including source namespace symbol resolution and source magic constants.

For unqualified namespace function/constant fallback, the resolution observed by `SourceBound` is effectively frozen at export time. Dynamically introducing a namespaced symbol after export does not retroactively change the generated expression.

`include`/`require` and `eval()` are rejected in **all** policies: their meaning depends on the location/scope of the generated artifact, so relocating an expression would change behavior.

`__FILE__` / `__DIR__` may be frozen to their build-time absolute source values under `SourcePathPolicy::AbsoluteBuildPath`; `SourcePathPolicy::Reject` rejects them.

### `ClosureExportPolicy::PortableExpression`

Use this for build/cache artifacts. In addition to the universal restrictions above, portable mode rejects constructs that would bind the artifact to its build environment, including:

- `__FILE__` / `__DIR__`;
- top-level anonymous `__FUNCTION__` / `__METHOD__` values containing an absolute source path;
- unqualified namespace function/constant fallback;
- provider-file-local functions that may not be loaded with the artifact;
- runtime user-defined constants whose definition is not guaranteed at load time.

Imported and fully-qualified external names remain valid.

## Closure class and trait scope

VarExport records lexical namespace/class/trait/function/method ownership separately from runtime closure binding scope. Source-owner metadata is checked against Reflection where PHP exposes it, so changing a class, method, trait, or named-function owner on disk after a runtime closure was created is rejected as stale source.

Class-scoped closures are restored with `Closure::bind()` after any required unbound-closure isolation. An explicit runtime rebind to one class is supported: `self::`, `parent::`, private/protected access follow runtime scope, while lexical magic constants retain source semantics.

Closures with a bound `$this` are rejected. If Reflection reports different runtime closure-scope and called classes, the late-static-binding state is also rejected because it cannot be reconstructed exactly.

Named callables are outside the anonymous-source contract. Consumers that need them should persist callable identity explicitly and reconstruct it with a class-specific strategy.

## Source consistency

The source file on disk must represent the source revision from which the runtime closure was compiled. VarExport compares observable Reflection/source metadata including declaration location, nested-closure depth, signature, defaults, capture names/reference mode (including implicit arrow-function captures), generator/static-local characteristics, function and parameter attribute names and safely verifiable arguments, and source-owner metadata where available.

PHP Reflection does not expose the original closure-body or source hash. Therefore **any source edit that preserves all observable metadata cannot be proven stale**; a body-only edit is the most common example. Long-running/hot-reload processes must recreate runtime closures after replacing their source before exporting them. The SHA-256 source cache guarantees freshness relative to the current file, not identity with an already-created runtime closure.

Parameter defaults and attribute arguments are never executed merely to prove source identity. Constant expressions that can be evaluated safely are compared. Expressions whose verification would require executing or autoloading user code, such as `new Foo()` or unresolved class-constant expressions, are rejected explicitly rather than treated as a match.

## Readonly value objects

Generic readonly-object export is disabled by default:

```php
$config = (new ExportConfig())
    ->withGenericReadonlyObjects();
```

In opt-in mode the class must be user-defined and non-anonymous; the constructor must be public; each parameter must map to a public promoted concrete hook-free property; variadic/by-reference constructor parameters, extra instance state, and `__unserialize()` hydration are rejected.

The generated expression executes the constructor again. Use generic reconstruction only for classes whose constructor is part of a stable value contract. Framework/cache descriptor types should normally use an explicit class-specific exporter.

## Recursive dispatcher and `ExportContext`

`VarExporter` is the single root recursive value dispatcher. `ExportContext` carries semantic depth, value path, base indentation, and active-object cycle state through nested arrays, objects, and closures.

Custom strategies supplied to the root `VarExporter` must preserve that context:

- object strategies implement `ContextualObjectExporterInterface`;
- closure strategies implement `ContextualClosureExporterInterface`.

Low-level exporters retain standalone composition APIs, but a composed root graph should be reconfigured through `VarExporter::withConfig()` so every collaborator receives the same immutable config and root dispatcher.

## Source cache

`ClosureSourceCache` is content-addressed by canonical path plus SHA-256 source fingerprint. It maintains bounded LRU storage and indexes closure candidates by the declaration line reported by Reflection. Sources allowed for one-off parsing but larger than the aggregate cache budget are not retained and do not evict unrelated retained entries. Returned candidates are deep detached copies, so AST transformations cannot mutate cache state.

Advanced callers may provide `ClosureSourceCacheInterface`; the default implementation is `Componenta\VarExport\Source\ClosureSourceCache`.

## Errors

All library exceptions implement `Componenta\VarExport\Contract\ExceptionInterface`. Internal parser/reflection failures are normalized at public exporter boundaries and retain their previous exception where useful.

```php
use Componenta\VarExport\Contract\ExceptionInterface;

try {
    $code = Export::var($value);
} catch (ExceptionInterface $e) {
    // Typed VarExport failure.
}
```

## Helper functions

```php
use function Componenta\VarExport\array_export;
use function Componenta\VarExport\closure_export;
use function Componenta\VarExport\var_export_pretty;
use function Componenta\VarExport\var_export_string;
```

The helper functions delegate to the `Export` facade.

## Quality gates

`composer test` uses Pest as the runner and executes the complete suite, including Pest-style regression tests and PHPUnit-compatible test classes. `composer check` runs style verification, PHPStan, and the test suite. `composer mutation` runs the configured mutation gate.

```bash
composer test
composer test-coverage
composer mutation
composer phpstan
composer cs-check
composer check
```

GitHub Actions is configured for PHP 8.4/8.5 with lowest/current dependency sets plus Composer validation, coverage, quality, and mutation jobs.

## Related packages

- `componenta/config` uses VarExport for executable configuration cache artifacts.
- `componenta/di` uses the same contextual value model for persistent DI cache graphs.

## License

MIT