<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Regression;

use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\SourcePathPolicy;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Export;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;
use Componenta\VarExport\VarExporter;

require_once __DIR__ . '/../Fixture/PortabilityClosures.php';

use function Componenta\VarExport\Tests\Fixture\PortableImported\importedFunctionClosure;
use function Componenta\VarExport\Tests\Fixture\PortableImported\qualifiedFunctionClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\evalClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\fileClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\unqualifiedConstantClosure;
use function Componenta\VarExport\Tests\Fixture\PortableUnqualified\unqualifiedFunctionClosure;

final readonly class DispatcherSpecial { public function __construct(public string $value) {} }
final readonly class DispatcherWrapper { public function __construct(public DispatcherSpecial $special) {} }

final readonly class DispatcherObjectExporter implements ContextualObjectExporterInterface
{
    public function __construct(private ExportConfig $config, private ?ContextualValueExporterInterface $valueExporter = null) {}
    public function export(object $object): string { return $this->exportWithContext($object, ExportContext::root()); }
    public function exportWithDepth(object $object, int $depth): string { return $this->exportWithContext($object, new ExportContext($depth)); }
    public function exportWithContext(object $object, ExportContext $context): string
    {
        if ($object instanceof DispatcherSpecial) { return 'new \\' . DispatcherSpecial::class . '(' . var_export($object->value, true) . ')'; }
        $fallback = new ObjectExporter($this->config->withGenericReadonlyObjects(), valueExporter: $this->valueExporter);
        return $fallback->exportWithContext($object, $context);
    }
    public function supports(object $object): bool { try { $this->export($object); return true; } catch (\Throwable) { return false; } }
    public function withConfig(ExportConfig $config): static { return new self($config, $this->valueExporter); }
    public function withValueExporter(ContextualValueExporterInterface $valueExporter): static { return new self($this->config, $valueExporter); }
}

it('routes nested objects back through the root contextual dispatcher', function (): void {
    $config = new ExportConfig();
    $exporter = new VarExporter($config, objectExporter: new DispatcherObjectExporter($config));
    $restored = eval('return ' . $exporter->export(new DispatcherWrapper(new DispatcherSpecial('nested'))) . ';');
    expect($restored)->toEqual(new DispatcherWrapper(new DispatcherSpecial('nested')));
});

it('rejects namespace fallback function calls in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(unqualifiedFunctionClosure(), $config))->toThrow(ClosureExportException::class, 'unqualified function');
});

it('rejects namespace fallback constants in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(unqualifiedConstantClosure(), $config))->toThrow(ClosureExportException::class, 'unqualified constant');
});

it('allows imported and fully-qualified functions in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    $imported = eval('return ' . Export::closure(importedFunctionClosure(), $config) . ';');
    $qualified = eval('return ' . Export::closure(qualifiedFunctionClosure(), $config) . ';');
    expect($imported())->toBe(3)->and($qualified())->toBe(3);
});

it('rejects source paths under explicit source-path policy', function (): void {
    $config = (new ExportConfig())->withSourcePathPolicy(SourcePathPolicy::Reject);
    expect(fn() => Export::closure(fileClosure(), $config))->toThrow(ClosureExportException::class, '__FILE__');
});

it('portable-expression policy always rejects source paths', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(fileClosure(), $config))->toThrow(ClosureExportException::class, '__FILE__');
});

it('rejects eval in portable-expression mode', function (): void {
    $config = (new ExportConfig())->withClosureExportPolicy(ClosureExportPolicy::PortableExpression);
    expect(fn() => Export::closure(evalClosure(), $config))->toThrow(ClosureExportException::class, 'eval()');
});
