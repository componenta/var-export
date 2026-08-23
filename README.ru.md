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
- замыкания с доступным исходным файлом;
- enum cases;
- реконструируемые readonly value objects, всё состояние которых представлено публичными concrete promoted-параметрами конструктора без hooks.

Сознательно отклоняются либо не сохраняются:

- resources;
- references внутри массивов и рекурсивные reference-массивы;
- identity при нескольких ссылках на один объект;
- mutable objects;
- anonymous readonly classes;
- readonly classes, состояние которых нельзя доказуемо восстановить через promoted constructor properties;
- closures, привязанные к `$this`;
- class-scoped closures, у которых lexical и called class различаются;
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
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\FormatterMode;

$config = new ExportConfig(
    mode: FormatterMode::Pretty,
    indent: '    ',       // один или несколько пробелов либо ровно один tab
    maxDepth: 64,
    sortKeys: false,
    trailingComma: true,
    closureUseMode: ClosureUseMode::Preserve,
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

Таким образом сохраняются lvalue/write semantics, локальная copy capture и вложенные scopes. `use (&$x)` отклоняется. Captured arrays ограничены `maxDepth` и не могут содержать references.

### `ClosureUseMode::Inline`

Используйте этот режим для self-contained cache artifacts. By-reference captures остаются неподдерживаемыми.

## Namespace и class scope closure

Source cache индексирует closures по строке и namespace. При экспорте фиксируется исходное разрешение unqualified functions/constants, class references становятся FQN, magic constants заменяются source-значениями.

Class-scoped closure поддерживается, если lexical и called class совпадают. Scope восстанавливается через `Closure::bind()`, поэтому `self::`, `parent::`, private/protected access и magic constants сохраняют семантику.

Closure с `$this` и late-static-binding сценарии с разными lexical/called classes отклоняются, поскольку exact reconstruction не гарантируется.

## Readonly value objects

Объект считается поддерживаемым только при доказуемой реконструкции состояния:

- класс `readonly` и не anonymous;
- constructor public;
- параметры не variadic и не by-reference;
- параметры являются public promoted concrete properties;
- virtual/property-hook свойства отклоняются;
- дополнительное instance state вне constructor parameters отклоняется.

`ObjectExporter::supports()` — точный preflight текущего instance: метод пробует построить representation без выполнения generated code и возвращает `false` при любом неподдерживаемом состоянии.

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
