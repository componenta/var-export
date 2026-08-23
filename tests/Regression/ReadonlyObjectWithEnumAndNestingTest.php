<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

enum TestPriority: string { case Low = 'low'; case High = 'high'; }
enum TestFlag { case Enabled; case Disabled; }
final readonly class Label { public function __construct(public string $text) {} }
final readonly class Task
{
    public function __construct(public string $title, public TestPriority $priority, public TestFlag $flag, public Label $label, public array $tags) {}
}

it('round-trips a backed enum value', function (): void {
    $code = Export::var(TestPriority::High); $evaluated = eval("return {$code};"); expect($evaluated)->toBe(TestPriority::High);
});

it('round-trips a pure enum case', function (): void {
    $code = Export::var(TestFlag::Enabled); $evaluated = eval("return {$code};"); expect($evaluated)->toBe(TestFlag::Enabled);
});

it('round-trips an explicitly enabled readonly object containing enums and nested objects', function (): void {
    $task = new Task('Ship release', TestPriority::High, TestFlag::Enabled, new Label('core'), ['urgent', 'release']);
    $config = (new ExportConfig())->withGenericReadonlyObjects();
    $code = Export::var($task, $config); $evaluated = eval("return {$code};");
    expect($evaluated)->toBeInstanceOf(Task::class)->and($evaluated->title)->toBe('Ship release')->and($evaluated->priority)->toBe(TestPriority::High)->and($evaluated->flag)->toBe(TestFlag::Enabled)->and($evaluated->label)->toBeInstanceOf(Label::class)->and($evaluated->label->text)->toBe('core')->and($evaluated->tags)->toBe(['urgent', 'release']);
});
