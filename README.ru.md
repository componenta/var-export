# Componenta VarExport

[![CI](https://github.com/componenta/var-export/actions/workflows/ci.yml/badge.svg)](https://github.com/componenta/var-export/actions/workflows/ci.yml)

Генерация детерминированных исполняемых PHP-выражений для значений, которые библиотека может безопасно воспроизвести.

Стабильная публичная поверхность: `Export`, `VarExporterInterface`, `VarExporter` и `ExportConfig`. Низкоуровневые контракты массива, замыкания, объекта и source cache предназначены для расширенной композиции.

[English version](README.md)

## Требования

- PHP 8.4+
- `nikic/php-parser` 5.8+

```bash
composer require componenta/var-export
```

## Поддерживаемая модель значений

VarExport использует **value semantics**. Поддерживаются:

- `null`, boolean, integer, float и произвольные byte strings;
- массивы без PHP references;
- anonymous source closures и arrow functions с доступным исходным файлом;
- enum cases;
- readonly value objects только при явном включении generic readonly export либо через class-specific exporter.

Сознательно отклоняются либо не сохраняются:

- resources;
- references внутри массивов и рекурсивные reference-массивы;
- identity при нескольких ссылках на один объект;
- mutable objects;
- anonymous readonly classes;
- generic readonly objects без явного opt-in;
- readonly classes, состояние которых нельзя доказуемо восстановить через promoted constructor properties;
- closures, привязанные к `$this`;
- late-static-binding closures, у которых runtime closure scope и called class различаются;
- closures, созданные из named callables (`Closure::fromCallable()`);
- closures со static local variables, чьё живое runtime-состояние нельзя безопасно установить в новом closure;
- closures с вложенными named-function или class-like declarations, identity которых нельзя надёжно сохранить expression-only экспортом;
- closures из `eval()` и другого кода без читаемого source file.

При `sortKeys=true` порядок associative array намеренно канонизируется, поэтому такой режим не обещает сохранение исходного iteration order.

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

Выражение с завершающей `;`:

```php
$expression = Export::toFile(['env' => 'prod']);
```

`toFile()` возвращает только expression + `;`, без `<?php` и `return`.

## Точное представление primitive values

Для primitive serialization используется нативное представление `var_export()`. За счёт этого экспорт finite float не зависит от INI `precision`, `PHP_INT_MIN` остаётся integer expression, а NUL/control/binary bytes строк восстанавливаются без octal-collision.

## Конфигурация

```php
use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;
use Componenta\VarExport\Config\SourcePathPolicy;

$config = new ExportConfig(
    mode: FormatterMode::Pretty,
    indent: '    ',       // один или несколько пробелов либо ровно один tab
    maxDepth: 64,
    sortKeys: false,
    trailingComma: true,
    closureUseMode: ClosureUseMode::Preserve,
    allowGenericReadonlyObjects: false,
    closureExportPolicy: ClosureExportPolicy::SourceBound,
    sourcePathPolicy: SourcePathPolicy::AbsoluteBuildPath,
);
```

`ExportConfig` immutable. Все `with*()` возвращают новый экземпляр.

### Сортировка ключей

При `sortKeys=true` integer keys сортируются численно и идут перед string keys; строки сортируются побайтово через `strcmp()`. Numeric-looking strings не переводятся в numeric comparison.

## Capture переменных closure

### `ClosureUseMode::Preserve` — режим по умолчанию

`Preserve` оставляет исходную lexical capture syntax и поэтому не является self-contained. Для executable cache используйте `Inline` явно. `Inline` фиксирует текущие capture **values** в creator expression, но не заменяет обращения к переменным внутри тела closure.

Концептуально:

```php
(static function () {
    $value = 42;

    return static function () use ($value) {
        $value++;
        return $value;
    };
})()
```

Таким образом сохраняются lvalue/write semantics, локальная copy capture и вложенные scopes. `use (&$x)` отклоняется. В `Inline` capture values намеренно ограничены `null`, scalar-значениями и вложенными массивами без references; objects (включая enum instances), resources и nested `Closure` отклоняются. Captured arrays подчиняются тому же глобальному `maxDepth`.

### `ClosureUseMode::Inline`

Используйте этот режим для self-contained cache artifacts. By-reference captures остаются неподдерживаемыми.

## Namespace и class scope closure

Source cache индексирует closures по строке и namespace. В режиме `SourceBound` фиксируется исходное разрешение unqualified functions/constants, class references становятся FQN, magic constants заменяются source-значениями.

Для build/cache артефактов используйте `ClosureExportPolicy::PortableExpression`. Такой режим всегда отклоняет `__FILE__`/`__DIR__`, `include`/`require`, `eval()` и namespace-fallback для unqualified functions/constants вместо создания непереносимого cache. Imported/FQ external functions разрешены, но функции, объявленные в source-файле самого provider, отклоняются: этот файл может не загружаться вместе с cache. Runtime user-defined constants также отклоняются, поскольку их объявление не гарантировано при загрузке артефакта. `SourcePathPolicy::Reject` дополнительно позволяет запретить `__FILE__`/`__DIR__` и в режиме `SourceBound`.

Class-scoped closure поддерживается, когда runtime closure scope и called class совпадают. Generated expression восстанавливает runtime scope через `Closure::bind()`, а lexical source metadata хранится отдельно для magic constants. Поэтому поддерживается и явный `Closure::bind()` к одному runtime class: `self::`, `parent::` и private/protected access следуют rebound scope, а lexical magic constants сохраняют исходную PHP-семантику.

Closure с `$this` и late-static-binding сценарии, где Reflection сообщает разные closure-scope и called classes, отклоняются, поскольку такое called-scope состояние нельзя восстановить точно. Closure со static local variables также отклоняются: Reflection показывает их текущее runtime-состояние, но PHP не предоставляет безопасного публичного механизма для установки этого состояния в заново созданное closure.

Parameter defaults также должны быть безопасно сравнимы без выполнения source-кода. Context-dependent defaults, которые parser не может безопасно вычислить (например `new Foo()`), явно отклоняются вместо предположения об эквивалентности.

`ClosureExporter` намеренно работает только с anonymous source closures/arrow functions. `Closure`, созданный из named function/method через `Closure::fromCallable()`, отклоняется; consumer, которому нужны named callables, должен сохранить callable identity своей стратегией и восстановить его явно. Вложенные named-function и class-like declarations также отклоняются, поскольку standalone expression не может надёжно воспроизвести их lexical declaration identity.

### Согласованность source

Экспорт closure основан на исходном файле. Файл на диске должен оставаться той же source revision, из которой был скомпилирован runtime closure. VarExport сравнивает текущий AST с доступными Reflection metadata — location, signature, defaults, capture names и reference mode, — но Reflection не предоставляет hash исходного тела closure. Поэтому **body-only изменение source при неизменных наблюдаемых Reflection metadata невозможно доказуемо обнаружить**. Long-running process/hot reload не должен экспортировать старый runtime closure после замены source-файла: closure следует сначала пересоздать из новой версии. SHA-256 source cache гарантирует актуальность относительно текущего файла, но не идентичность уже созданному runtime closure.

## Readonly value objects

Generic readonly export **выключен по умолчанию**. Reflection не позволяет доказать, что произвольный instance действительно был создан конструктором: объект может быть hydrated через Reflection/serialization, а повторный вызов конструктора способен выбросить исключение или изменить поведение.

Для контролируемых constructor value objects доступен явный opt-in:

```php
$config = (new ExportConfig())
    ->withGenericReadonlyObjects();
```

В opt-in режиме класс должен быть user-defined, `readonly` и не anonymous, constructor — public, параметры — public promoted concrete properties без hooks, variadic/by-reference и дополнительного instance state. Internal/extension classes отклоняются и требуют class-specific exporter. `__unserialize()` также отклоняется. Generated expression всё равно вызывает constructor при восстановлении, поэтому для framework/cache descriptors предпочтителен class-specific exporter.

## Единый recursive dispatcher

При использовании через `VarExporter` все вложенные значения снова проходят через корневой dispatcher. `ArrayExporter` и `ObjectExporter` не выбирают стратегию для nested values самостоятельно. `ExportContext` переносит depth и path, а custom object exporters, передаваемые в `VarExporter`, должны реализовывать `ContextualObjectExporterInterface`. Это принципиальная часть контракта: специальные типы остаются видимыми на любой глубине и не обходятся fallback-экспортёром. Переконфигурировать связанный graph следует через `VarExporter::withConfig()`; low-level `withConfig()` у отдельных exporter-ов является standalone composition API и намеренно не сохраняет ранее привязанный root dispatcher.

## Переиспользование и source cache

```php
use Componenta\VarExport\VarExporter;

$exporter = new VarExporter(ExportConfig::pretty());
$a = $exporter->export($closureA);
$b = $exporter->export($closureB);
```

Source cache использует canonical path и SHA-256 fingerprint содержимого, а не секундный `filemtime()`. Вместо повторного полного AST scan хранится индекс closures. Выдаваемые AST nodes являются deep-detached copies, поэтому visitor transformations не изменяют cache state.

Для расширения доступен `ClosureSourceCacheInterface`; реализация по умолчанию — `Source\ClosureSourceCache`.

## Исключения

Все library exceptions реализуют `Componenta\VarExport\Contract\ExceptionInterface`.

```php
use Componenta\VarExport\Contract\ExceptionInterface;

try {
    $code = Export::var($value);
} catch (ExceptionInterface $e) {
    // Ошибка VarExport.
}
```

Внутренние parser/reflection errors нормализуются на публичной границе exporter и при необходимости сохраняются в `previous`.

## Helper functions

`var_export_string()`, `var_export_pretty()`, `array_export()` и `closure_export()` являются convenience wrappers над стабильным `Export` facade.

## Quality gates

`composer check` запускает style verification, PHPStan и полный Pest suite, включая regression tests. `composer mutation` запускает встроенный mutation testing Pest с порогом 70% для покрытого кода. GitHub Actions проверяет PHP 8.4/8.5 на lowest/current dependency sets и отдельный mutation gate на PHP 8.5.

```bash
composer test
composer mutation
composer phpstan
composer cs-check
composer check
```

## Связанные пакеты

- `componenta/config` использует VarExport для executable configuration cache;
- `componenta/app` и `componenta/di` могут использовать то же детерминированное представление для compiled metadata.

## Лицензия

MIT
