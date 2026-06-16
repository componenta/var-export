<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Internal;

use Componenta\VarExport\Internal\MagicConstantResolver;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\TestCase;

final class MagicConstantResolverTest extends TestCase
{
    public function testResolvesFileConstant(): void
    {
        $resolver = new MagicConstantResolver(null, '/path/to/file.php');
        $result = $resolver->enterNode(new MagicConst\File());

        self::assertInstanceOf(String_::class, $result);
        self::assertSame('/path/to/file.php', $result->value);
    }

    public function testResolvesDirConstant(): void
    {
        $resolver = new MagicConstantResolver(null, '/path/to/file.php');
        $result = $resolver->enterNode(new MagicConst\Dir());

        self::assertInstanceOf(String_::class, $result);
        self::assertSame('/path/to', $result->value);
    }

    public function testResolvesNamespaceConstant(): void
    {
        $resolver = new MagicConstantResolver(
            new Namespace_(new Name('App\\Service')),
            '/test/file.php',
        );
        $result = $resolver->enterNode(new MagicConst\Namespace_());

        self::assertInstanceOf(String_::class, $result);
        self::assertSame('App\\Service', $result->value);
    }

    public function testResolvesNamespaceConstantAtGlobalScope(): void
    {
        $resolver = new MagicConstantResolver(null, '/test/file.php');
        $result = $resolver->enterNode(new MagicConst\Namespace_());

        self::assertInstanceOf(String_::class, $result);
        self::assertSame('', $result->value);
    }

    public function testResolvesLineConstant(): void
    {
        $resolver = new MagicConstantResolver(null, '/test/file.php');
        $node = new MagicConst\Line();
        $node->setAttributes(['startLine' => 42]);

        $result = $resolver->enterNode($node);

        self::assertInstanceOf(Int_::class, $result);
        self::assertSame(42, $result->value);
    }

    public function testClassContextConstantsBecomeEmptyString(): void
    {
        $resolver = new MagicConstantResolver(null, '/test/file.php');

        foreach ([new MagicConst\Class_(), new MagicConst\Method(), new MagicConst\Function_(), new MagicConst\Trait_()] as $node) {
            $result = $resolver->enterNode($node);
            self::assertInstanceOf(String_::class, $result);
            self::assertSame('', $result->value);
        }
    }

    public function testLeavesUnrelatedNodesUntouched(): void
    {
        $resolver = new MagicConstantResolver(null, '/test/file.php');

        self::assertNull($resolver->enterNode(new Int_(42)));
    }
}
