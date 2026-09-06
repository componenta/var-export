# Componenta VarExport

Componenta VarExport преобразует поддерживаемые PHP-значения в детерминированные исполняемые PHP-выражения. Библиотека предназначена для генерации файлов конфигурации и DI: она возвращает только expression, не записывает файлы и не добавляет PHP-тег, `return` или завершающую точку с запятой.

Требуется PHP 8.4 или новее.

## Установка

```bash
composer require componenta/var-export:^2.0@dev
```

## Быстрый старт

```php
use Componenta\VarExport\VarExport;

$exporter = VarExport::withDefaults();

$expression = $exporter->export([
    'debug' => false,
    'ports' => [80, 443],
]);
```

Результат можно сразу встроить в генерируемый PHP-файл:

```php
[
    "debug" => false,
    "ports" => [
        80,
        443,
    ],
]
```

## Публичный API

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

`VarExport` вызывает экспортёры в порядке конструктора. `null` означает, что экспортёр не поддерживает значение. Любая строка, включая пустую, завершает цепочку.

`withDefaults()` устанавливает публичные экспортёры в таком порядке:

1. `ScalarExporter`
2. `ArrayExporter`
3. `EnumExporter`
4. `ClosureExporter`

## Поддерживаемые значения

- `null`, bool, int, float и string;
- `INF`, `-INF`, `NAN`, signed zero, управляющие символы и бинарные байты строк;
- enum cases в виде полностью квалифицированного `\ClassName::Case`;
- массивы с сохранением порядка, отступом в четыре пробела и trailing comma;
- closures с доступным исходным кодом и безопасными scalar, enum и array captures, встроенными в expression.

Экспортёры по умолчанию не поддерживают произвольные объекты и resources. Интеграции Config и DI могут добавить свои экспортёры перед defaults:

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

Составной экспортёр реализует `VarExportInterface` и `VarExportAwareInterface`. Метод `withVarExport()` должен вернуть связанную копию. Оркестратор заменяет исходный экземпляр этой копией, поэтому экземпляр можно безопасно переиспользовать.

`ArrayExporter` и `ClosureExporter` являются составными экспортёрами. Перед прямым использованием их нужно связать:

```php
$arrayExporter = (new ArrayExporter())
    ->withVarExport(VarExport::withDefaults());

$expression = $arrayExporter->export(['answer' => 42]);
```

## Portable closures

`ClosureExporter` читает исходник closure, сверяет позицию с Reflection, разрешает переносимые глобальные функции и константы, замораживает безопасные captures и печатает каноническое выражение.

Автоматические глобальные переменные PHP (`$GLOBALS`, `$_SERVER`, `$_ENV` и остальные superglobals) остаются runtime-обращениями. Они не считаются captures и не замораживаются в сгенерированном expression.

Сгенерированный expression сохраняет режим `strict_types` исходника-провайдера. При загрузке из файла с противоположным режимом выбрасывается `ExportException`, поэтому поведение closure не меняется молча.

Экспорт отклоняется для поведения, которое нельзя безопасно воспроизвести в сгенерированном файле:

- captures по ссылке и ссылки внутри captured arrays;
- capture-значения глубже 64 уровней обхода;
- `$this`, anonymous или несовместимый class scope и static local state;
- captured objects, resources и closures;
- named callables;
- `eval`, include/require, переключения PHP mode, внешние `declare`-директивы кроме `strict_types`, magic constants, вложенные именованные объявления, неразрешимые unqualified-символы с namespace fallback, runtime user constants и функции, существующие только в исходнике-провайдере;
- отсутствующий, структурно изменённый, неоднозначный или невалидный исходник.

Исходник-провайдер нельзя изменять после создания runtime closure. PHP Reflection не предоставляет opcodes тела closure, поэтому изменение только тела с сохранением позиции, сигнатуры и captures нельзя надёжно обнаружить без исполнения пользовательского кода.

## Ошибки

Все исключения библиотеки реализуют `Componenta\VarExport\Exception\ExceptionInterface`.

- `ExportException` означает, что экспортёр распознал значение, но не смог построить безопасное выражение.
- `UnsupportedValueException` означает, что все экспортёры вернули `null`.

Исключения пользовательских экспортёров не перехватываются.

## Разработка

```bash
composer validate --strict
composer cs-check
composer phpstan
composer test
composer test-coverage
composer mutation
```

Поддерживаемая тестовая матрица: PHP 8.4 и PHP 8.5.
