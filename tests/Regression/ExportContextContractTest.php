<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\ExportContext;
use InvalidArgumentException;

it('builds semantic child locations while sharing cycle-detection state', function (): void {
    $root = ExportContext::root();
    $first = $root->child('items', '  ');
    $second = $first->child(3, '    ');

    expect($root->depth)->toBe(0)
        ->and($root->path)->toBe([])
        ->and($root->location())->toBe('root')
        ->and($first->depth)->toBe(1)
        ->and($first->path)->toBe(['items'])
        ->and($first->baseIndent)->toBe('  ')
        ->and($first->location())->toBe("\$value['items']")
        ->and($second->depth)->toBe(2)
        ->and($second->path)->toBe(['items', 3])
        ->and($second->location())->toBe("\$value['items'][3]")
        ->and($first->activeObjects)->toBe($root->activeObjects)
        ->and($second->activeObjects)->toBe($root->activeObjects);
});

it('changes indentation without losing path, depth, or cycle-detection state', function (): void {
    $context = ExportContext::root()->child('value', '  ');
    $reindented = $context->withBaseIndent("\t");

    expect($reindented)->not->toBe($context)
        ->and($reindented->depth)->toBe($context->depth)
        ->and($reindented->path)->toBe($context->path)
        ->and($reindented->baseIndent)->toBe("\t")
        ->and($reindented->activeObjects)->toBe($context->activeObjects)
        ->and($reindented->location())->toBe($context->location());
});

it('rejects a negative export depth at the context boundary', function (): void {
    expect(fn() => new ExportContext(depth: -1))
        ->toThrow(InvalidArgumentException::class, 'must be non-negative');
});
