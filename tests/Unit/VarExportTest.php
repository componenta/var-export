<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Unit;

use Componenta\VarExport\Exception\ExceptionInterface;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\Exception\UnsupportedValueException;
use Componenta\VarExport\Tests\Double\AwareExporterSpy;
use Componenta\VarExport\Tests\Double\AwarenessState;
use Componenta\VarExport\Tests\Double\ExporterCallLog;
use Componenta\VarExport\Tests\Double\FixedResultExporter;
use Componenta\VarExport\Tests\Double\RecordingExporter;
use Componenta\VarExport\Tests\Double\ThrowingExporter;
use Componenta\VarExport\VarExport;
use DomainException;
use LogicException;
use stdClass;
use Throwable;

it('passes the original value in order and stops at the first string result', function (): void {
    $value = new stdClass();
    $calls = new ExporterCallLog();
    $first = new RecordingExporter($calls, 'first', null);
    $second = new RecordingExporter($calls, 'second', 'exported');
    $last = new RecordingExporter($calls, 'last', 'too late');

    $result = new VarExport($first, $second, $last)->export($value);

    expect($result)->toBe('exported')
        ->and($calls->all())->toBe([
            ['first', $value],
            ['second', $value],
        ]);
});

it('treats an empty string as a successful export', function (): void {
    $empty = new FixedResultExporter('');
    $unexpected = new ThrowingExporter(new LogicException('must not be called'));

    expect(new VarExport($empty, $unexpected)->export('value'))->toBe('');
});

it('immutably binds aware exporters to the constructing orchestrator', function (): void {
    $state = new AwarenessState();
    $aware = new AwareExporterSpy($state);

    $varExport = new VarExport($aware);
    $result = $varExport->export('value');

    expect($aware->isBound())->toBeFalse()
        ->and($result)->toBe((string) spl_object_id($varExport))
        ->and($state->boundTo)->toBe($varExport)
        ->and($state->exportedBy)->not->toBe($aware);
});

it('reuses one aware exporter safely across independent orchestrators', function (): void {
    $aware = new AwareExporterSpy();
    $first = new VarExport($aware);
    $second = new VarExport($aware);

    expect($first->export('value'))->toBe((string) spl_object_id($first))
        ->and($second->export('value'))->toBe((string) spl_object_id($second));
});

it('throws a marked unsupported-value exception when no exporter accepts the value', function (): void {
    $exception = null;

    try {
        new VarExport()->export(new stdClass());
    } catch (UnsupportedValueException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(UnsupportedValueException::class)
        ->and($exception)->toBeInstanceOf(ExportException::class)
        ->and($exception)->toBeInstanceOf(ExceptionInterface::class)
        ->and($exception?->getMessage())->toContain('stdClass');
});

it('does not intercept exceptions from custom exporters', function (): void {
    $failure = new DomainException('custom failure');
    $caught = null;

    try {
        new VarExport(new ThrowingExporter($failure))->export('value');
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
});
