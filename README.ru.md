# Componenta VarExport

[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-243%20passing-brightgreen.svg)](tests)

Round-trip значений PHP в исполняемый исходный код. В отличие от `var_export()`, библиотека умеет **замыкания**, **readonly value objects** и **enum'ы** — и сохраняет namespace-семантику, чтобы выгруженный код корректно исполнялся в любом месте.

[English version](README.md)

---

## Возможности

- **Замыкания** — экспорт через AST с корректным резолвингом имён (классы → FQN, функции/константы сохраняют глобальный fallback)
- **Readonly value objects** — round-trip через `new ClassName(...)` для классов, где каждый параметр конструктора — публичное свойство
- **Enum'ы** — pure и backed
- **Массивы** — последовательные, ассоциативные, смешанные, любая вложенность (с защитой `maxDepth`)
- **Форматирование** — pretty или compact, настраиваемый отступ, сортировка ключей, trailing commas
- **Типизированные исключения** — точный контекст ошибки без раскрытия исходных значений

---

## Требования

- PHP 8.4+
- `nikic/php-parser` ^5.0

---

## Связанные пакеты

| Пакет | Зачем нужен здесь |
|---|---|
| `componenta/config` | Использует экспорт PHP-значений для файлов кеша конфигурации. |
| `componenta/app` | Кеш приложения может сохранять скомпилированные массивы и описания в PHP-файлах. |
| `componenta/di` | Скомпилированные DI-планы и dependency cache должны быть исполняемыми PHP-массивами. |
| `nikic/php-parser` | Нужен для корректного экспорта замыканий и сохранения семантики имён. |

---

## Установка

```bash
composer require componenta/var-export
```

---

## Быстрый старт

```php
use Componenta\VarExport\Export;

// Любое значение → исполняемый PHP-код
Export::var(['host' => 'localhost', 'port' => 5432]);
// → ['host' => 'localhost', 'port' => 5432]

// Pretty-вывод (многострочный + trailing comma)
Export::pretty([1, 2, 3]);
// → [
//       1,
//       2,
//       3,
//   ]

// Замыкания — с учётом namespace
$handler = static fn(int $x): int => $x * 2;
Export::closure($handler);
// → static fn(int $x): int => $x * 2

// Для записи в файл — добавляет `;`
Export::toFile(['env' => 'prod']);
// → ['env' => 'prod'];
```

Round-trip работает через `eval()` и любой PHP-файл:

```php
$code = Export::var($original);
$restored = eval("return {$code};");
// $restored === $original
```

---

## Конфигурация

```php
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\FormatterMode;

$config = new ExportConfig(
    mode:           FormatterMode::Pretty,
    indent:         '    ',                 // пробелы или табы
    maxDepth:       64,                     // защита от рекурсии
    sortKeys:       false,                  // сортировать ассоциативные ключи
    trailingComma:  true,                   // запятая после последнего элемента в pretty
    closureUseMode: ClosureUseMode::Preserve,
);

// Пресеты
ExportConfig::pretty();   // многострочно + trailing comma
ExportConfig::compact();  // одной строкой

// Иммутабельные копии (with* возвращают новый экземпляр)
$config = ExportConfig::pretty()
    ->withIndent("\t")
    ->withSortKeys();
```

### Переиспользование экспортёра

Разовые вызовы идут через статический фасад. Для множества экспортов с одной конфигурацией — создайте `VarExporter` напрямую: кэш распарсенных AST переиспользуется между вызовами.

```php
use Componenta\VarExport\VarExporter;

$exporter = new VarExporter(ExportConfig::pretty());
$a = $exporter->export($closure1);
$b = $exporter->export($closure2);  // AST файла $closure1 уже в кэше
```

---

## Режимы захвата переменных в замыкании

### `ClosureUseMode::Preserve` (по умолчанию)

Сохраняет `use(...)` как есть. Захваченные переменные должны быть определены в области, где исполняется выгруженный код.

```php
$multiplier = 2;
$fn = function (int $x) use ($multiplier): int {
    return $x * $multiplier;
};

Export::closure($fn);
// → function (int $x) use ($multiplier): int { return $x * $multiplier; }
```

### `ClosureUseMode::Inline`

Заменяет каждую переменную из `use(...)` её текущим значением — получается самодостаточное замыкание:

```php
$config = new ExportConfig(closureUseMode: ClosureUseMode::Inline);

Export::closure($fn, $config);
// → function (int $x): int { return $x * 2; }
```

Inline принимает скалярные захваты и вложенные массивы скаляров. Объекты, ресурсы, вложенные замыкания и by-reference (`use (&$x)`) отклоняются с `ClosureExportException`.

---

## Экспорт объектов

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

Требования к readonly-классу для экспорта:

- Класс помечен `readonly`
- Каждый параметр конструктора имеет одноимённое `public` свойство

`ObjectExporter::supports($object)` честно отвечает, подойдёт ли объект — используйте для preflight-проверки недоверенного ввода.

---

## Helper-функции

Для тех, кому удобнее свободные функции:

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

## Обработка ошибок

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
    // Превышен maxDepth, неэкспортируемый элемент, путь до ключа в $e->context
} catch (ClosureExportException $e) {
    // Замыкание привязано к $this, inline-захват недопустим, неоднозначная позиция
} catch (ConfigurationException $e) {
    // Некорректный indent / maxDepth в ExportConfig
} catch (ExportException $e) {
    // Неподдерживаемый тип верхнего уровня
}
```

У каждого исключения в `$context` — метаданные (класс, путь к ключу, имена переменных, файл/строка). Сами значения, вызвавшие ошибку, **не сохраняются** — логи остаются безопасными.

---

## Что не поддерживается

- Мутабельные объекты (не readonly, либо со свойствами, которые нельзя восстановить через конструктор)
- Ресурсы (включая stream/curl)
- Замыкания с привязкой к `$this` — перед экспортом конвертируйте в `static function() { ... }`
- Замыкания, определённые в `eval()`'d коде (нет исходного файла для парсинга)
- Два и более замыкания на одной строке с одинаковой сигнатурой (неоднозначно — разнесите на разные строки)

---

## Лицензия

MIT
