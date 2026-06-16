<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Export;

/*
 * Scenario coverage: readonly objects carrying enums, other readonly
 * objects and mixed structural payloads. The earlier test suite only
 * exercised readonly objects with scalar/array properties - this file
 * locks down that the round-trip survives the richer shapes the README
 * advertises.
 */

enum TestPriority: string
{
    case Low = 'low';
    case High = 'high';
}

enum TestFlag
{
    case Enabled;
    case Disabled;
}

final readonly class Label
{
    public function __construct(public string $text) {}
}

final readonly class Task
{
    public function __construct(
        public string $title,
        public TestPriority $priority,
        public TestFlag $flag,
        public Label $label,
        public array $tags,
    ) {}
}

it('round-trips a backed enum value', function (): void {
    $code = Export::var(TestPriority::High);
    $evaluated = eval("return {$code};");

    expect($evaluated)->toBe(TestPriority::High);
});

it('round-trips a pure enum case', function (): void {
    $code = Export::var(TestFlag::Enabled);
    $evaluated = eval("return {$code};");

    expect($evaluated)->toBe(TestFlag::Enabled);
});

it('round-trips a readonly object containing enums and nested objects', function (): void {
    $task = new Task(
        title: 'Ship release',
        priority: TestPriority::High,
        flag: TestFlag::Enabled,
        label: new Label('core'),
        tags: ['urgent', 'release'],
    );

    $code = Export::var($task);
    $evaluated = eval("return {$code};");

    expect($evaluated)->toBeInstanceOf(Task::class)
        ->and($evaluated->title)->toBe('Ship release')
        ->and($evaluated->priority)->toBe(TestPriority::High)
        ->and($evaluated->flag)->toBe(TestFlag::Enabled)
        ->and($evaluated->label)->toBeInstanceOf(Label::class)
        ->and($evaluated->label->text)->toBe('core')
        ->and($evaluated->tags)->toBe(['urgent', 'release']);
});
