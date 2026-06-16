# Componenta VarExport

[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-243%20passing-brightgreen.svg)](tests)

Round-trip PHP values to executable source code. Unlike `var_export()`, this library handles **closures**, **readonly value objects** and **enums** — and keeps namespace semantics intact so the exported code evaluates correctly anywhere.

[Русская версия](README.ru.md)

---

## Features

- **Closures** — full AST-based export with correct name resolution (class refs → FQN, function/constant refs keep global fallback)
- **Readonly value objects** — round-tripped via `new ClassName(...)` when every constructor parameter is a public property
- **Enums** — both pure and backed cases
- **Arrays** — sequential, associative, mixed, unlimited nesting (guarded by `maxDepth`)
- **Configurable output** — pretty or compact layout, custom indent, sorted keys, trailing commas
- **Typed exceptions** — precise error context without leaking the values that caused them

---

## Requirements

- PHP 8.4+
- `nikic/php-parser` ^5.0

## Related Packages

| Package | Why it matters here |
|---|---|
| `componenta/config` | Uses export for executable PHP configuration cache files. |
| `componenta/app` | Application cache can persist compiled arrays and descriptors as PHP files. |
| `componenta/di` | Compiled DI plans and dependency cache should be executable PHP arrays. |
| `nikic/php-parser` | Required for closure export and name-resolution semantics. |

---

## Installation

```bash
composer require componenta/var-export
```

---

## Quick start

```php
use Componenta\VarExport\Export;

// Any value — returns executable PHP code
Export::var(['host' => 'localhost', 'port' => 5432]);
// → ['host' => 'localhost', 'port' => 5432]

// Pretty layout (multi-line + trailing comma)
Export::pretty([1, 2, 3]);
// → [
//       1,
//       2,
//       3,
//   ]

// Closures — namespace-aware
$handler = static fn(int $x): int => $x * 2;
Export::closure($handler);
// → static fn(int $x): int => $x * 2

// For file output — appends the semicolon
Export::toFile(['env' => 'prod']);
// → ['env' => 'prod'];
```

Round-trip works through `eval()` (and any PHP source file):

```php
$code = Export::var($original);
$restored = eval("return {$code};");
// $restored === $original
```

---

## Configuration

```php
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\FormatterMode;

$config = new ExportConfig(
    mode:           FormatterMode::Pretty,
    indent:         '    ',                 // spaces or tabs
    maxDepth:       64,                     // guards runaway recursion
    sortKeys:       false,                  // sort associative array keys
    trailingComma:  true,                   // add trailing comma in pretty mode
    closureUseMode: ClosureUseMode::Preserve,
);

// Presets
ExportConfig::pretty();   // multi-line + trailing comma
ExportConfig::compact();  // single-line

// Fluent copies (every with* returns a new instance)
$config = ExportConfig::pretty()
    ->withIndent("\t")
    ->withSortKeys();
```

### Reusing an exporter

One-off calls go through the static facade. For many exports with the same configuration, instantiate `VarExporter` directly — the parsed-AST cache is reused across calls:

```php
use Componenta\VarExport\VarExporter;

$exporter = new VarExporter(ExportConfig::pretty());
$a = $exporter->export($closure1);
$b = $exporter->export($closure2);  // closure1's file AST is cached
```

---

## Closure capture modes

### `ClosureUseMode::Preserve` (default)

Keeps the `use(...)` clause verbatim. The captured variables must exist in the scope where the exported code runs.

```php
$multiplier = 2;
$fn = function (int $x) use ($multiplier): int {
    return $x * $multiplier;
};

Export::closure($fn);
// → function (int $x) use ($multiplier): int { return $x * $multiplier; }
```

### `ClosureUseMode::Inline`

Replaces each `use(...)` variable with its current value, yielding a self-contained closure:

```php
$config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);

Export::closure($fn, $config);
// → function (int $x): int { return $x * 2; }
```

Inline mode accepts scalar captures and nested scalar arrays. Captures of objects, resources, nested closures or by-reference variables (`use (&$x)`) are rejected with `ClosureExportException`.

---

## Exporting objects

```php
enum Priority: string {
    case Low = 'low';
    case High = 'high';
}

final readonly class Task {
    public function __construct(
        public string $title,
        public Priority $priority,
        public array $tags,
    ) {}
}

Export::var(new Task('Ship', Priority::High, ['core']));
// → new \App\Task('Ship', \App\Priority::High, ['core'])
```

Requirements for readonly-class export:

- Class is marked `readonly`
- Every constructor parameter has a matching `public` property

`ObjectExporter::supports($object)` reports up front whether a given object satisfies these rules — use it to pre-flight untrusted input.

---

## Helper functions

For callers that prefer free functions over static methods:

```php
use function Componenta\VarExport\var_export_string;
use function Componenta\VarExport\var_export_pretty;
use function Componenta\VarExport\array_export;
use function Componenta\VarExport\closure_export;

var_export_string($value);
var_export_string($value, pretty: true);
var_export_pretty($value);
array_export([1, 2, 3]);
closure_export(fn() => 42);
```

---

## Error handling

```php
use Componenta\VarExport\Exception\{
    ExportException,
    ArrayExportException,
    ClosureExportException,
    ConfigurationException,
};

try {
    $code = Export::var($data);
} catch (ArrayExportException $e) {
    // Max depth, unexportable element, key path context in $e->context
} catch (ClosureExportException $e) {
    // Bound $this, inline captures not supported, ambiguous location
} catch (ConfigurationException $e) {
    // Invalid indent / maxDepth in ExportConfig
} catch (ExportException $e) {
    // Unsupported top-level type
}
```

Every exception carries a `$context` array with metadata (class, key path, variable names, file/line) — values that caused the failure are **not** stored, so logs stay safe.

---

## Not supported

- Mutable objects (non-readonly, or with private state that cannot be reconstructed through the constructor)
- Resources (including stream/curl handles)
- Closures bound to `$this` — convert to `static function() { ... }` before export
- Closures defined in `eval()`'d code (no source file to parse)
- Two or more closures on the same line with identical signatures (ambiguous — keep them on separate lines)

---

## License

MIT
