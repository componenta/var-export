<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Internal;

use Componenta\VarExport\Internal\MagicConstantResolver;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\TestCase;

final class MagicConstantResolverTest extends TestCase
{
    private MagicConstantResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MagicConstantResolver(
            '/path/to/file.php',
            'App\\Service',
            '{closure:App\\Service\\factory():42}',
            'App\\Service\\Handler',
            'App\\Service\\Behavior',
        );
    }

    public function testResolvesFileAndDir(): void
    {
        $file = $this->resolver->enterNode(new MagicConst\File());
        $dir = $this->resolver->enterNode(new MagicConst\Dir());

        self::assertInstanceOf(String_::class, $file);
        self::assertSame('/path/to/file.php', $file->value);
        self::assertInstanceOf(String_::class, $dir);
        self::assertSame('/path/to', $dir->value);
    }

    public function testResolvesNamespaceAndScopeConstants(): void
    {
        $namespace = $this->resolver->enterNode(new MagicConst\Namespace_());
        $class = $this->resolver->enterNode(new MagicConst\Class_());
        $trait = $this->resolver->enterNode(new MagicConst\Trait_());
        $method = $this->resolver->enterNode(new MagicConst\Method());
        $function = $this->resolver->enterNode(new MagicConst\Function_());
        $property = $this->resolver->enterNode(new MagicConst\Property());

        self::assertSame('App\\Service', $namespace->value);
        self::assertSame('App\\Service\\Handler', $class->value);
        self::assertSame('App\\Service\\Behavior', $trait->value);
        self::assertSame('{closure:App\\Service\\factory():42}', $method->value);
        self::assertSame('{closure:App\\Service\\factory():42}', $function->value);
        self::assertSame('', $property->value);
    }

    public function testResolvesLine(): void
    {
        $node = new MagicConst\Line();
        $node->setAttributes(['startLine' => 42]);
        $result = $this->resolver->enterNode($node);

        self::assertInstanceOf(Int_::class, $result);
        self::assertSame(42, $result->value);
    }

    public function testLeavesUnrelatedNodesUntouched(): void
    {
        self::assertNull($this->resolver->enterNode(new Int_(42)));
    }
}
