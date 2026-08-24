# Componenta VarExport

[![CI](https://github.com/componenta/var-export/actions/workflows/ci.yml/badge.svg)](https://github.com/componenta/var-export/actions/workflows/ci.yml)

Генерация детерминированных исполняемых PHP-выражений для значений, которые библиотека может безопасно воспроизвести.

Стабильная публичная поверхность: `Export`, `VarExporterInterface`, `VarExporter` и `ExportConfig`. Для расширенной композиции доступны contextual-контракты массивов, замыканий, объектов и source cache.

[English version](README.md)

## Требования

- PHP 8.4+
- `nikic/php-parser` 5.8+

```bash
composer require componenta/var-export
```

## Модель значений

VarExport использует **value semantics**. Поддерживаются:

- `null`, boolean, integer, float и произвольные byte strings;
- массивы без PHP references;
- анонимные source closures и arrow functions с читаемым исходным файлом;
- enum cases;
- явно включённые строгие user-defined readonly constructor value objects;
- class-specific object strategies потребителей.

Сознательно отклоняются либо не сохраняются:

- resources;
- references в массивах и recursive reference arrays;
- повторная object identity (`===`) в generic value model;
- mutable objects;
- anonymous/internal generic readonly objects;
- closures, привязанные к объекту через `$this`;
- named-callable closures из `Closure::fromCallable()`;
- static local variables closure;
- late-static-binding state, когда Reflection сообщает разные closure-scope и called class;
- вложенные named-function и class-like declarations внутри экспортируемого closure;
- closures из `eval()` и кода без читаемого source file.

При `sortKeys=true` порядок associative array намеренно канонизируется, поэтому исходный iteration order не гарантируется.

## Быстрый старт

```php
use Componenta\VarExport\Export;

$code = Export::var([
    'host' => 'localhost',
    'port' => 5432,
    'ratio' => 0.10000000000000002,
]);

$restored = eval("return {$code};");
```

Для expression с завершающей `;` используется явный statement API:

```php
$statement = Export::statement(['env' => 'prod']);
```

`statement()` / `VarExporter::exportStatement()` не добавляют `<?php` или `return`: VarExport экспортирует expressions/statements, а не готовый PHP-файл.

## Primitive representation

Finite floats, включая signed zero, восстанавливаются bit-exactly независимо от INI `precision`. `PHP_INT_MIN` остаётся integer expression, а NUL/control/binary bytes строк восстанавливаются без octal ambiguity.

Non-finite floats генерируются как namespace-safe `\INF`, `-\INF`, `\NAN`. `NaN` остаётся `NaN`, но payload/signaling bits NaN не входят в value contract.

## Конфигурация

```php
use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Config\SourcePathPolicy;

$config = new ExportConfig(
    mode: FormatterMode::Pretty,
    indent: '    ',
    maxDepth: 64,
    sortKeys: false,
    trailingComma: true,
    closureUseMode: ClosureUseMode::Preserve,
    allowGenericReadonlyObjects: false,
    closureExportPolicy: ClosureExportPolicy::SourceBound,
    sourcePathPolicy: SourcePathPolicy::AbsoluteBuildPath,
);
```

`ExportConfig` immutable. `maxDepth` считается от semantic root depth `0` и одинаково применяется к каждому вложенному value: массивам, constructor arguments объектов, closures и Inline capture values.

При `sortKeys=true` integer keys сортируются численно раньше string keys, строки — побайтово через `strcmp()`. Numeric-looking strings остаются строками.

## Capture переменных closure

### `ClosureUseMode::Preserve` — по умолчанию

`Preserve` сохраняет lexical captures как переменные. Generated expression не self-contained: captured variables должны существовать в scope, где expression будет вычислен.

By-reference captures сохраняют reference semantics. By-value captures сохраняют обычную PHP copy semantics; изменение локальной копии внутри одного вызова не становится состоянием следующего вызова. Static locals как отдельное runtime state библиотека отклоняет.

### `ClosureUseMode::Inline`

`Inline` замораживает поддерживаемые capture **values** в static creator expression:

```php
$config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);
```

Поддерживаются:

- `null`, boolean, integer, float, string;
- enum cases;
- вложенные arrays из поддерживаемых значений без PHP references.

Object instances, resources, nested `Closure` objects и by-reference captures отклоняются вместо тихого изменения identity/reference semantics. Для captures действует тот же глобальный `maxDepth`.

Для non-static closure с исходным `$this === null` closure создаётся за static creator boundary до восстановления class scope. Поэтому exported expression, вычисленный внутри метода другого объекта, не получает его ambient `$this`.

## Source, namespace и relocation closure

Экспорт closures source-based. Текущий source file разбирается `nikic/php-parser`; names и magic constants трансформируются только там, где семантику можно сохранить.

### `ClosureExportPolicy::SourceBound`

`SourceBound` сохраняет source-семантику, которую можно заморозить в standalone expression: source namespace resolution и magic constants.

`include`/`require` и `eval()` отклоняются **во всех** policy: их поведение зависит от расположения/scope generated artifact и меняется при relocation.

`__FILE__` / `__DIR__` могут быть заморожены в build-time absolute path при `SourcePathPolicy::AbsoluteBuildPath`; `SourcePathPolicy::Reject` запрещает их.

### `ClosureExportPolicy::PortableExpression`

Режим для build/cache artifacts. Дополнительно отклоняются конструкции, привязывающие artifact к build environment:

- `__FILE__` / `__DIR__`;
- top-level anonymous `__FUNCTION__` / `__METHOD__`, содержащие абсолютный source path;
- unqualified namespace function/constant fallback;
- provider-file-local functions, которые могут отсутствовать при загрузке artifact;
- runtime user-defined constants, существование которых не гарантировано.

Imported/FQ external names разрешены.

## Class и trait scope closure

VarExport отдельно хранит lexical namespace/class/trait/function/method owner и runtime binding scope closure. Source-owner metadata сравнивается с Reflection там, где PHP предоставляет достаточную информацию; смена class, method, trait или named-function owner на диске после создания runtime closure отклоняется как stale source.

Class-scoped closure восстанавливается через `Closure::bind()` после isolation unbound closure. Явный runtime rebind к одному классу поддерживается: `self::`, `parent::`, private/protected access используют runtime scope, а lexical magic constants сохраняют source semantics.

Closure с bound `$this` отклоняется. Если Reflection сообщает разные runtime closure-scope и called class, late-static-binding state считается невоспроизводимым и также отклоняется.

Named callables не относятся к anonymous-source contract. Consumer, которому они нужны, должен сохранить callable identity явно и восстановить её class-specific strategy.

## Source consistency

Source file на диске должен соответствовать revision, из которого был скомпилирован runtime closure. VarExport сравнивает доступную Reflection metadata: location, signature, defaults, capture names/reference mode и source owner.

Reflection PHP не предоставляет исходный hash тела closure. Поэтому **body-only изменение, не меняющее наблюдаемую metadata, невозможно доказуемо обнаружить**. В long-running/hot-reload процессе после замены source необходимо заново создать runtime closures перед экспортом. SHA-256 source cache гарантирует свежесть относительно текущего файла, но не тождество уже созданному runtime closure.

Parameter defaults не исполняются ради проверки source identity. Defaults, которые невозможно безопасно проверить constant-expression evaluator'ом, например `new Foo()`, отклоняются явно.

## Readonly value objects

Generic readonly export по умолчанию выключен:

```php
$config = (new ExportConfig())
    ->withGenericReadonlyObjects();
```

В opt-in режиме class должен быть user-defined и non-anonymous; constructor — public; каждый parameter — public promoted concrete hook-free property. Variadic/by-reference constructor parameters, дополнительный instance state и `__unserialize()` hydration отклоняются.

Generated expression повторно вызывает constructor. Generic reconstruction следует использовать только для value classes со стабильным constructor contract. Framework/cache descriptors предпочтительно экспортировать class-specific strategy.

## Recursive dispatcher и `ExportContext`

`VarExporter` — единый root recursive value dispatcher. `ExportContext` переносит semantic depth, value path, base indentation и active-object cycle state через вложенные arrays, objects и closures.

Custom strategies, передаваемые в root `VarExporter`, обязаны сохранять context:

- object strategy реализует `ContextualObjectExporterInterface`;
- closure strategy реализует `ContextualClosureExporterInterface`.

Low-level exporters сохраняют standalone composition API, но весь root graph следует reconfigure через `VarExporter::withConfig()`, чтобы immutable config и dispatcher оставались едиными.

## Source cache

`ClosureSourceCache` content-addressed по canonical path + SHA-256 fingerprint source. Cache ограничен LRU-budget и индексирует closure candidates по source line. Возвращаемые AST candidates deep-detached, поэтому visitor transformations не мутируют cache state.

Для advanced composition доступен `ClosureSourceCacheInterface`; default implementation — `Componenta\VarExport\Source\ClosureSourceCache`.

## Ошибки

Все исключения библиотеки реализуют `Componenta\VarExport\Contract\ExceptionInterface`. Parser/reflection failures нормализуются на публичных exporter boundaries и при необходимости сохраняются в `previous`.

## Helper functions

`var_export_string()`, `var_export_pretty()`, `array_export()` и `closure_export()` — convenience wrappers над `Export` facade.

## Quality gates

`composer test` использует Pest как runner и запускает весь suite, включая Pest-style regressions и PHPUnit-compatible TestCase tests. `composer check` включает style verification, PHPStan и tests. `composer mutation` запускает mutation gate.

```bash
composer test
composer mutation
composer phpstan
composer cs-check
composer check
```

GitHub Actions настроен на PHP 8.4/8.5, lowest/current dependencies, quality и mutation jobs.

## Связанные пакеты

- `componenta/config` использует VarExport для executable configuration cache;
- `componenta/di` использует тот же contextual value model для persistent DI cache graph.

## Лицензия

MIT
