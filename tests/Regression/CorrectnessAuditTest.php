<?php

declare(strict_types=1);

use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ArrayExportException;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Exception\ConfigurationException;
use Componenta\VarExport\Export;
use Componenta\VarExport\ObjectExporter;
use Componenta\VarExport\Source\ClosureSourceCache;
use Componenta\VarExport\VarExporter;
use PhpParser\Node\Scalar\Int_;
use PhpParser\PrettyPrinter\Standard;

require_once __DIR__ . '/../Fixture/ClosureFactories.php';
require_once __DIR__ . '/../Fixture/MultiNamespaceClosures.php';

use Componenta\VarExport\Tests\Fixture\ByReferenceConstructorValueObject;
use Componenta\VarExport\Tests\Fixture\LateStaticChild;
use Componenta\VarExport\Tests\Fixture\NonPromotedValueObject;
use Componenta\VarExport\Tests\Fixture\PrivateConstructorValueObject;
use Componenta\VarExport\Tests\Fixture\ScopedClosureFixture;
use Componenta\VarExport\Tests\Fixture\SupportedValueObject;
use Componenta\VarExport\Tests\Fixture\TraitConsumer;

use function Componenta\VarExport\Tests\Fixture\arrowCaptureFactory;
use function Componenta\VarExport\Tests\Fixture\captureFactory;
use function Componenta\VarExport\Tests\Fixture\localSymbolClosure;
use function Componenta\VarExport\Tests\Fixture\magicClosure;
use function Componenta\VarExport\Tests\Fixture\nestedCaptureFactory;

it('round-trips finite floats bit exactly and independently of precision', function (): void {
    $values = [0.0, -0.0, 0.10000000000000002, 1.0000000000000002, 1.2345678901234567, 9007199254740991.0, PHP_FLOAT_MIN, PHP_FLOAT_MAX];
    $old = ini_get('precision');
    try { foreach ([10, 14, 17] as $precision) { ini_set('precision', (string) $precision); foreach ($values as $value) { $restored = eval('return ' . Export::var($value) . ';'); expect(pack('d', $restored))->toBe(pack('d', $value)); } } }
    finally { if ($old !== false) { ini_set('precision', $old); } }
});

it('round-trips PHP_INT_MIN as an integer', function (): void { $restored = eval('return ' . Export::var(PHP_INT_MIN) . ';'); expect($restored)->toBe(PHP_INT_MIN)->and($restored)->toBeInt(); });

it('round-trips NUL before every decimal digit and arbitrary bytes', function (): void {
    foreach (range(0, 9) as $digit) { $value = "\0" . (string) $digit . '2'; expect(eval('return ' . Export::var($value) . ';'))->toBe($value); }
    $bytes = implode('', array_map(chr(...), range(0, 255))); expect(eval('return ' . Export::var($bytes) . ';'))->toBe($bytes);
});

it('does not mutate cached AST between runtime closures from one source location', function (): void {
    $exporter = new VarExporter(new ExportConfig(closureUseMode: ClosureUseMode::Inline));
    $first = eval('return ' . $exporter->export(arrowCaptureFactory(1)) . ';'); $second = eval('return ' . $exporter->export(arrowCaptureFactory(2)) . ';'); $third = eval('return ' . $exporter->export(arrowCaptureFactory(3)) . ';');
    expect($first())->toBe(1)->and($second())->toBe(2)->and($third())->toBe(3);
});

it('preserves captured variable lvalue semantics in Inline mode', function (): void { $restored = eval('return ' . Export::closure(captureFactory(4), new ExportConfig(closureUseMode: ClosureUseMode::Inline)) . ';'); expect($restored())->toBe(5)->and($restored())->toBe(6); });
it('preserves nested closure capture scopes in Inline mode', function (): void { $outer = eval('return ' . Export::closure(nestedCaptureFactory('nested'), new ExportConfig(closureUseMode: ClosureUseMode::Inline)) . ';'); $inner = $outer(); expect($inner())->toBe('nested'); });
it('freezes source namespace function and constant resolution', function (): void { $restored = eval('return ' . Export::closure(localSymbolClosure()) . ';'); expect($restored())->toBe(['fixture-function', 'fixture-constant']); });
it('preserves magic constants of an unscoped source closure', function (): void { $closure = magicClosure(); $expected = $closure(); $restored = eval('return ' . Export::closure($closure) . ';'); expect($restored())->toBe($expected); });
it('restores a safe class scope and its magic constants', function (): void { $closure = ScopedClosureFixture::make(); $expected = $closure(); $restored = eval('return ' . Export::closure($closure) . ';'); expect($restored())->toBe($expected); });
it('preserves trait and consuming class magic constants', function (): void { $closure = TraitConsumer::makeTraitClosure(); $expected = $closure(); $restored = eval('return ' . Export::closure($closure) . ';'); expect($restored())->toBe($expected); });
it('rejects class scopes whose called class differs from lexical scope', function (): void { expect(fn() => Export::closure(LateStaticChild::make()))->toThrow(ClosureExportException::class, 'lexical class'); });

it('uses the namespace belonging to the actual closure when a file declares several namespaces', function (): void {
    $first = \Componenta\VarExport\Tests\Fixture\First\Factory::make(); $second = \Componenta\VarExport\Tests\Fixture\Second\Factory::make();
    $firstRestored = eval('return ' . Export::closure($first) . ';'); $secondRestored = eval('return ' . Export::closure($second) . ';');
    expect($firstRestored())->toBe('Componenta\\VarExport\\Tests\\Fixture\\First')->and($secondRestored())->toBe('Componenta\\VarExport\\Tests\\Fixture\\Second');
});

it('rejects array references rather than losing alias semantics', function (): void { $value = 42; $array = ['value' => &$value]; expect(fn() => Export::var($array))->toThrow(ArrayExportException::class, 'array reference'); });

it('uses bytewise canonical ordering for string keys', function (): void {
    $code = Export::var(['2e0' => 1, '10e0' => 2, '02' => 3, '1e1' => 4], new ExportConfig(sortKeys: true));
    expect(strpos($code, "'02'"))->toBeLessThan(strpos($code, "'10e0'"))->and(strpos($code, "'10e0'"))->toBeLessThan(strpos($code, "'1e1'"))->and(strpos($code, "'1e1'"))->toBeLessThan(strpos($code, "'2e0'"));
});

it('rebuilds the full collaborator graph in withConfig', function (): void {
    $value = 9; $closure = static function () use ($value): int { return $value; }; $object = new SupportedValueObject(1, ['closure' => $closure, 'z' => 2, 'a' => 1]);
    $original = new VarExporter((new ExportConfig(closureUseMode: ClosureUseMode::Inline))->withGenericReadonlyObjects());
    $reconfigured = $original->withConfig(ExportConfig::pretty()->withGenericReadonlyObjects()->withSortKeys()->withClosureUseMode(ClosureUseMode::Preserve));
    $code = $reconfigured->export($object); expect($code)->toContain("\n")->and($code)->toContain('use ($value)')->and(strpos($code, "'a'"))->toBeLessThan(strpos($code, "'z'"));
});

it('preflights explicitly enabled generic readonly objects', function (): void {
    $exporter = new ObjectExporter((new ExportConfig())->withGenericReadonlyObjects()); $value = 'reference'; $anonymous = new readonly class ('x') { public function __construct(public string $value) {} };
    expect($exporter->supports(new SupportedValueObject(1, [])))->toBeTrue()->and($exporter->supports(new NonPromotedValueObject('x')))->toBeFalse()->and($exporter->supports(PrivateConstructorValueObject::make('x')))->toBeFalse()->and($exporter->supports(new ByReferenceConstructorValueObject($value)))->toBeFalse()->and($exporter->supports($anonymous))->toBeFalse();
});

it('round-trips explicitly enabled readonly value objects', function (): void { $value = new SupportedValueObject(7, ['b' => 2, 'a' => 1]); $config = (new ExportConfig(sortKeys: true))->withGenericReadonlyObjects(); $restored = eval('return ' . Export::var($value, $config) . ';'); expect($restored)->toEqual(new SupportedValueObject(7, ['a' => 1, 'b' => 2])); });

it('does not replace closures with placeholders in standalone ArrayExporter', function (): void { $arrayExporter = new ArrayExporter(); $closure = static fn(): int => 42; expect(fn() => $arrayExporter->export([$closure]))->toThrow(ArrayExportException::class, 'no ClosureExporterInterface'); });
it('rejects indentation unsupported by php-parser at configuration boundary', function (): void { expect(fn() => new ExportConfig(indent: "\t\t"))->toThrow(ConfigurationException::class); expect(fn() => new ExportConfig(indent: " \t "))->toThrow(ConfigurationException::class); expect(new ExportConfig(indent: "\t"))->toBeInstanceOf(ExportConfig::class); expect(new ExportConfig(indent: '  '))->toBeInstanceOf(ExportConfig::class); });
it('applies maxDepth to arrays exported through standalone ObjectExporter', function (): void { $exporter = new ObjectExporter((new ExportConfig(maxDepth: 2))->withGenericReadonlyObjects()); $object = new SupportedValueObject(1, [[[['too-deep']]]]); expect(fn() => $exporter->export($object))->toThrow(ArrayExportException::class, 'Maximum nesting depth'); });

it('applies maxDepth and reference checks to captured arrays', function (): void {
    $deep = [[[['too-deep']]]]; $deepClosure = static fn(): array => $deep; expect(fn() => Export::closure($deepClosure, new ExportConfig(maxDepth: 2, closureUseMode: ClosureUseMode::Inline)))->toThrow(ClosureExportException::class, 'exceeds maxDepth');
    $scalar = 1; $referenced = ['value' => &$scalar]; $referenceClosure = static fn(): array => $referenced; expect(fn() => Export::closure($referenceClosure, new ExportConfig(closureUseMode: ClosureUseMode::Inline)))->toThrow(ClosureExportException::class, 'array reference');
});

it('invalidates source cache by content even when mtime does not change', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_cache_' . bin2hex(random_bytes(6)) . '.php'; $mtime = time() - 100; $cache = new ClosureSourceCache(); $printer = new Standard();
    try { file_put_contents($file, "<?php\n\$fn = static fn() => 1;\n"); touch($file, $mtime); $first = $cache->candidates($file, 2)[0]; expect($printer->prettyPrintExpr($first->node))->toContain('1'); file_put_contents($file, "<?php\n\$fn = static fn() => 2;\n"); touch($file, $mtime); $second = $cache->candidates($file, 2)[0]; expect($printer->prettyPrintExpr($second->node))->toContain('2'); } finally { @unlink($file); }
});

it('returns detached source candidates from the cache', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_detached_' . bin2hex(random_bytes(6)) . '.php'; $cache = new ClosureSourceCache(); $printer = new Standard();
    try { file_put_contents($file, "<?php\n\$fn = static fn() => 1;\n"); $first = $cache->candidates($file, 2)[0]; if ($first->node instanceof \PhpParser\Node\Expr\ArrowFunction) { $first->node->expr = new Int_(999); } $second = $cache->candidates($file, 2)[0]; expect($printer->prettyPrintExpr($second->node))->toContain('1')->not->toContain('999'); } finally { @unlink($file); }
});

it('matches closure parameter/return/default metadata before selecting source', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_signature_' . bin2hex(random_bytes(6)) . '.php';
    try { file_put_contents($file, "<?php\nreturn static fn(int \$x = 1): int => \$x + 1;\n"); $closure = require $file; file_put_contents($file, "<?php\nreturn static fn(int \$x = 2): int => \$x + 1;\n"); expect(fn() => Export::closure($closure))->toThrow(ClosureExportException::class, 'no longer matches'); } finally { @unlink($file); }
});

it('bounds closure source and aggregate cache sizes', function (): void {
    expect(fn() => new ClosureSourceCache(maxEntries: 0))->toThrow(ConfigurationException::class, 'maxEntries');
    $oversized = sys_get_temp_dir() . '/componenta_var_export_oversized_' . bin2hex(random_bytes(6)) . '.php'; $one = sys_get_temp_dir() . '/componenta_var_export_budget_one_' . bin2hex(random_bytes(6)) . '.php'; $two = sys_get_temp_dir() . '/componenta_var_export_budget_two_' . bin2hex(random_bytes(6)) . '.php';
    try { file_put_contents($oversized, "<?php\n\$fn = static fn() => 1;\n"); expect(fn() => (new ClosureSourceCache(maxSourceBytes: 8))->candidates($oversized, 2))->toThrow(ClosureExportException::class, 'configured source limit'); file_put_contents($one, "<?php\n\$fn = static fn() => 1;\n"); file_put_contents($two, "<?php\n\$fn = static fn() => 2;\n"); $bytes = max(strlen((string) file_get_contents($one)), strlen((string) file_get_contents($two))); $cache = new ClosureSourceCache(maxEntries: 8, maxSourceBytes: 1024, maxCachedSourceBytes: $bytes + 4); $cache->candidates($one, 2); $cache->candidates($two, 2); expect($cache->size())->toBe(1); } finally { @unlink($oversized); @unlink($one); @unlink($two); }
});

it('detects stale source metadata instead of selecting a different closure', function (): void {
    $file = sys_get_temp_dir() . '/componenta_var_export_stale_' . bin2hex(random_bytes(6)) . '.php';
    try { file_put_contents($file, "<?php\nreturn static fn(int \$x): int => \$x + 1;\n"); $closure = require $file; file_put_contents($file, "<?php\nreturn static fn(int \$y): int => \$y + 1;\n"); expect(fn() => Export::closure($closure))->toThrow(ClosureExportException::class, 'no longer matches'); } finally { @unlink($file); }
});
