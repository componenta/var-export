# Componenta VarExport

[![CI](https://github.com/componenta/var-export/actions/workflows/ci.yml/badge.svg)](https://github.com/componenta/var-export/actions/workflows/ci.yml)

Generate deterministic executable PHP expressions for values that the library can reproduce safely.

The stable entry points are `Export`, `VarExporterInterface`, `VarExporter`, and `ExportConfig`. Lower-level array, closure, object, and source-cache contracts are available for advanced composition.

[Русская версия](README.ru.md)

## Requirements

- PHP 8.4+
- `nikic/php-parser` 5.8+

```bash
composer require componenta/var-export
```

## Supported value model

VarExport uses **value semantics**. It supports:

- `null`, booleans, integers, floats, and arbitrary byte strings;
- arrays that do not contain PHP references;
- anonymous source closures and arrow functions whose source file is available;
- enum cases;
- readonly value objects only when generic readonly export is explicitly enabled, or when a caller supplies a class-specific object exporter.

It deliberately rejects or does not preserve:

- resources;
- array references and recursive reference arrays;
- object identity between repeated references to the same instance;
- mutable objects;
- anonymous readonly classes;
- generic readonly objects unless explicitly enabled;
- readonly classes whose state cannot be proven reconstructable from promoted constructor properties;
- closures bound to `$this`;
- late-static-binding closures where the runtime closure scope and called class differ;
- closures created from named callables (`Closure::fromCallable()`);
- closures with static local variables, because their live runtime state cannot be seeded into a reconstructed closure;
- closures containing nested named-function or class-like declarations, whose declaration identity cannot be preserved by an expression-only exporter;
- closures created by `eval()` or otherwise lacking readable source.

When `sortKeys` is enabled, associative-array iteration order is intentionally canonicalized and therefore is not an order-preserving round trip.

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

For a complete PHP expression with a terminating semicolon:

```php
$expression = Export::toFile(['env' => 'prod']);
// ['env' => 'prod'];
```

`toFile()` returns an expression plus `;`; it does not add `<?php` or `return`.

## Exact primitive representation

Primitive serialization is delegated to PHP's own `var_export()` representation. This avoids precision-dependent float formatting, preserves `PHP_INT_MIN` as an integer expression, and correctly handles NUL/control/binary string bytes.

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

`ExportConfig` is immutable; every `with*()` method returns a new copy.

```php
$config = ExportConfig::pretty()
    ->withIndent("\t")
    ->withSortKeys();
```

### Key sorting

With `sortKeys: true`, integer keys are ordered numerically before string keys, and string keys are ordered bytewise with `strcmp()`. Numeric-looking strings are still strings and never switch to numeric comparison.

## Closure captures

### `ClosureUseMode::Preserve` — default

`Preserve` keeps the original lexical capture syntax. The generated expression is source-oriented rather than self-contained: required variables must exist where the generated code is evaluated.

Use `Inline` explicitly when a self-contained executable representation is required. `Inline` freezes capture **values** into a creator expression while leaving the original closure body unchanged.

Given:

```php
$multiplier = 2;
$fn = static function (int $x) use ($multiplier): int {
    $multiplier++;

    return $x * $multiplier;
};
```

VarExport conceptually emits:

```php
(static function () {
    $multiplier = 2;

    return static function (int $x) use ($multiplier): int {
        $multiplier++;

        return $x * $multiplier;
    };
})()
```

This preserves local write/lvalue semantics and nested closure scopes. By-reference captures (`use (&$x)`) are rejected rather than silently changed. Inline capture values are intentionally limited to `null`, scalar values, and nested reference-free arrays; objects (including enum instances), resources, and nested `Closure` objects are rejected. Captured arrays are subject to the same global `maxDepth` policy.

### `ClosureUseMode::Inline`

```php
$config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
```

Use this mode for self-contained cache artifacts. By-reference captures remain unsupported.

## Closure namespaces and class scope

The source cache indexes closures by source line and namespace. In the default `SourceBound` policy, generated code freezes source namespace resolution for unqualified function/constant calls at export time, fully qualifies class references, and substitutes magic constants with their source values.

For build artifacts use `ClosureExportPolicy::PortableExpression`. In this mode VarExport rejects source-location-dependent constructs instead of producing a cache whose behavior can differ after deployment: `__FILE__`/`__DIR__`, `include`/`require`, `eval()`, and unqualified function/constant fallback inside namespaces are rejected. Imported or fully-qualified external functions remain valid; functions defined in the closure provider source file are rejected because that file may not be loaded with the artifact. Runtime user-defined constants are rejected as well because their definition is not guaranteed to be present when the artifact is loaded. `SourcePathPolicy::Reject` can additionally forbid `__FILE__`/`__DIR__` in `SourceBound` mode.

Class-scoped closures are preserved when the runtime closure scope and called class agree. The generated expression restores the runtime scope using `Closure::bind()`, while lexical source metadata is retained separately for magic constants. This also supports an explicit `Closure::bind()` rebind to a single runtime class: `self::`/`parent::`/private access follow the rebound scope, while source lexical magic constants keep PHP semantics.

Closures bound to an object (`$this`) and late-static-binding cases where Reflection reports different closure-scope and called classes are rejected because that called-scope state cannot be reconstructed exactly. Closures with static local variables are also rejected because Reflection exposes their live state but PHP provides no safe public mechanism to seed that state into a newly reconstructed closure.

Parameter defaults must also be safely comparable without executing source code. Context-dependent defaults that the parser cannot evaluate safely (for example `new Foo()` defaults) are rejected explicitly rather than guessed.

`ClosureExporter` is intentionally an anonymous-source exporter. A `Closure` created from a named function or method with `Closure::fromCallable()` is rejected; consumers that need named callables should serialize the callable identity explicitly and reconstruct it with a class-specific strategy. Nested named-function and class-like declarations are rejected as well because a standalone expression cannot reliably reproduce their lexical declaration identity.

### Source consistency

Closure export is source-based. The source file on disk must still represent the same source revision from which the runtime closure was compiled. VarExport compares the current AST with Reflection metadata (location, signature, defaults, capture names and reference mode), but PHP Reflection does not expose the original closure-body hash. Consequently, a **body-only source edit that preserves all observable Reflection metadata cannot be proven stale**. Long-running processes and hot-reload tooling must not export an old runtime closure after replacing its source file; recreate the closure from the new source first. The SHA-256 source cache guarantees freshness relative to the current file, not identity with an already-created runtime closure.

## Readonly value objects

Generic readonly-object export is **disabled by default**. Structural reflection cannot prove that an arbitrary instance was created by the constructor: reflection/unserialization hydration can produce state for which replaying the constructor throws or changes behavior. Prefer a class-specific exporter for framework/cache descriptor types.

Explicit opt-in is available for controlled constructor value objects:

```php
enum Priority: string
{
    case Low = 'low';
    case High = 'high';
}

final readonly class Task
{
    public function __construct(
        public string $title,
        public Priority $priority,
        public array $tags,
    ) {
    }
}

$config = (new ExportConfig())
    ->withGenericReadonlyObjects();

$code = Export::var(
    new Task('Ship', Priority::High, ['core']),
    $config,
);
```

In opt-in mode the class must be user-defined and the constructor must be public; parameters must not be variadic or by-reference and must be promoted public properties. Internal/extension classes, virtual/hooked properties, extra instance state, anonymous classes and `__unserialize()` hydration are rejected. The generated expression still executes the constructor at restore time, so opt in only for classes whose constructor is part of their stable value contract.

## Recursive dispatcher

`VarExporter` is the single recursive value dispatcher. Arrays and generic objects never select a nested exporter on their own when they are used through `VarExporter`; nested values are routed back through the root dispatcher with an `ExportContext` containing depth and value path. Custom object exporters supplied to `VarExporter` must implement `ContextualObjectExporterInterface`. This is intentional: every nested value must return to the same root dispatcher so framework-specific values remain visible at every nesting level instead of being bypassed by a fallback exporter. Reconfigure a composed graph through `VarExporter::withConfig()`; low-level exporter `withConfig()` methods are standalone composition APIs and intentionally do not retain a previously bound root dispatcher.

## Reusing exporters and source cache

```php
use Componenta\VarExport\VarExporter;

$exporter = new VarExporter(ExportConfig::pretty());
$a = $exporter->export($closureA);
$b = $exporter->export($closureB);
```

Closure source is cached by canonical path plus a SHA-256 content fingerprint. The cache stores a closure index instead of repeatedly scanning a full AST. Returned AST candidates are deep detached copies, so export transformations cannot mutate cached state.

Advanced callers can supply `ClosureSourceCacheInterface`; the default implementation is `Source\ClosureSourceCache`.

## Errors

All library exceptions implement `Componenta\VarExport\Contract\ExceptionInterface`.

```php
use Componenta\VarExport\Contract\ExceptionInterface;

try {
    $code = Export::var($value);
} catch (ExceptionInterface $e) {
    // Typed library failure.
}
```

Specific exception classes remain available for array, closure, configuration, and general export failures. Internal parser/reflection failures are normalized at public exporter boundaries and retained as `previous` exceptions where applicable.

## Helper functions

```php
use function Componenta\VarExport\array_export;
use function Componenta\VarExport\closure_export;
use function Componenta\VarExport\var_export_pretty;
use function Componenta\VarExport\var_export_string;
```

The functions delegate to the stable `Export` facade.

## Quality gates

`composer check` runs formatting verification, PHPStan, and the complete Pest test suite, including regression tests. `composer mutation` runs Pest mutation testing with a 70% covered-code mutation-score gate. GitHub Actions exercises PHP 8.4/8.5 with lowest/current dependencies and runs the mutation gate on PHP 8.5.

```bash
composer test
composer mutation
composer phpstan
composer cs-check
composer check
```

## Related packages

- `componenta/config` uses VarExport for executable configuration cache artifacts.
- `componenta/app` and `componenta/di` can use the same deterministic PHP-expression representation for compiled metadata.

## License

MIT
